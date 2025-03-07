<?php
/**
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 * OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * This software consists of voluntary contributions made by many individuals
 * and is licensed under the LGPL. For more information please see
 * <http://phing.info>.
 */

namespace Phing\Listener;

use Phing\Exception\BuildException;
use Phing\Io\IOException;
use Phing\Io\OutputStream;
use Phing\Phing;
use Phing\Project;
use Phing\Util\StringHelper;

/**
 * Writes a build event to the console.
 *
 * Currently, it only writes which targets are being executed, and
 * any messages that get logged.
 *
 * @author    Andreas Aderhold <andi@binarycloud.com>
 * @copyright 2001,2002 THYRELL. All rights reserved
 * @see       BuildEvent
 */
class DefaultLogger implements StreamRequiredBuildLogger
{
    /**
     *  Size of the left column in output. The default char width is 12.
     *
     * @var int
     */
    public const LEFT_COLUMN_SIZE = 12;

    /**
     *  The message output level that should be used. The default is
     *  <code>Project::MSG_VERBOSE</code>.
     *
     * @var int
     */
    protected $msgOutputLevel = Project::MSG_ERR;

    /**
     *  Time that the build started
     *
     * @var int
     */
    protected $startTime;

    /**
     * @var OutputStream Stream to use for standard output.
     */
    protected $out;

    /**
     * @var OutputStream Stream to use for error output.
     */
    protected $err;

    protected $emacsMode = false;

    /**
     *  Construct a new default logger.
     */
    public function __construct()
    {
    }

    /**
     *  Set the msgOutputLevel this logger is to respond to.
     *
     *  Only messages with a message level lower than or equal to the given
     *  level are output to the log.
     *
     *  <p> Constants for the message levels are in Project.php. The order of
     *  the levels, from least to most verbose, is:
     *
     *  <ul>
     *    <li>Project::MSG_ERR</li>
     *    <li>Project::MSG_WARN</li>
     *    <li>Project::MSG_INFO</li>
     *    <li>Project::MSG_VERBOSE</li>
     *    <li>Project::MSG_DEBUG</li>
     *  </ul>
     *
     *  The default message level for DefaultLogger is Project::MSG_ERR.
     *
     * @param int $level The logging level for the logger.
     * @see   BuildLogger#setMessageOutputLevel()
     */
    public function setMessageOutputLevel($level)
    {
        $this->msgOutputLevel = (int) $level;
    }

    /**
     * Sets the output stream.
     *
     * @see   BuildLogger#setOutputStream()
     */
    public function setOutputStream(OutputStream $output)
    {
        $this->out = $output;
    }

    /**
     * Sets the error stream.
     *
     * @see   BuildLogger#setErrorStream()
     */
    public function setErrorStream(OutputStream $err)
    {
        $this->err = $err;
    }

    /**
     * Sets this logger to produce emacs (and other editor) friendly output.
     *
     * @param bool $emacsMode <code>true</code> if output is to be unadorned so that
     *                        emacs and other editors can parse files names, etc.
     */
    public function setEmacsMode($emacsMode)
    {
        $this->emacsMode = $emacsMode;
    }

    /**
     *  Sets the start-time when the build started. Used for calculating
     *  the build-time.
     *
     */
    public function buildStarted(BuildEvent $event)
    {
        $this->startTime = microtime(true);
        if ($this->msgOutputLevel >= Project::MSG_INFO) {
            $this->printMessage(
                "Buildfile: " . $event->getProject()->getProperty("phing.file"),
                $this->out,
                Project::MSG_INFO
            );
        }
    }

    /**
     *  Prints whether the build succeeded or failed, and any errors that
     *  occurred during the build. Also outputs the total build-time.
     *
     * @see   BuildEvent::getException()
     */
    public function buildFinished(BuildEvent $event)
    {
        $msg = PHP_EOL . $this->getBuildSuccessfulMessage() . PHP_EOL;
        $error = $event->getException();

        if ($error !== null) {
            $msg = PHP_EOL . $this->getBuildFailedMessage() . PHP_EOL;

            self::throwableMessage($msg, $error, Project::MSG_VERBOSE <= $this->msgOutputLevel);
        }
        $msg .= PHP_EOL . "Total time: " . static::formatTime(microtime(true) - $this->startTime) . PHP_EOL;

        $error === null
            ? $this->printMessage($msg, $this->out, Project::MSG_VERBOSE)
            : $this->printMessage($msg, $this->err, Project::MSG_ERR);
    }

    public static function throwableMessage(&$msg, $error, $verbose)
    {
        while ($error instanceof BuildException) {
            $cause = $error->getPrevious();
            if ($cause === null) {
                break;
            }
            $msg1 = trim($error);
            $msg2 = trim($cause);
            if (StringHelper::endsWith($msg2, $msg1)) {
                $msg .= StringHelper::substring($msg1, 0, strlen($msg1) - strlen($msg2) - 1);
                $error = $cause;
            } else {
                break;
            }
        }

        if ($verbose) {
            if ($error instanceof BuildException) {
                $msg .= $error->getLocation() . PHP_EOL;
            }
            $msg .= '[' . get_class($error) . '] ' . $error->getMessage() . PHP_EOL . $error->getTraceAsString() . PHP_EOL;
        } else {
            $msg .= ($error instanceof BuildException ? $error->getLocation() . " " : "") . $error->getMessage() . PHP_EOL;
        }

        if ($error->getPrevious() && $verbose) {
            $error = $error->getPrevious();
            do {
                $msg .= '[Caused by ' . get_class($error) . '] ' . $error->getMessage() . PHP_EOL . $error->getTraceAsString() . PHP_EOL;
            } while ($error = $error->getPrevious());
        }
    }

    /**
     * Get the message to return when a build failed.
     *
     * @return string The classic "BUILD FAILED"
     */
    protected function getBuildFailedMessage()
    {
        return "BUILD FAILED";
    }

    /**
     * Get the message to return when a build succeeded.
     *
     * @return string The classic "BUILD FINISHED"
     */
    protected function getBuildSuccessfulMessage()
    {
        return "BUILD FINISHED";
    }

    /**
     *  Prints the current target name
     *
     * @see   BuildEvent::getTarget()
     */
    public function targetStarted(BuildEvent $event)
    {
        if (
            Project::MSG_INFO <= $this->msgOutputLevel
            && $event->getTarget()->getName() != ''
        ) {
            $showLongTargets = $event->getProject()->getProperty("phing.showlongtargets");
            $msg = PHP_EOL . $event->getProject()->getName() . ' > ' . $event->getTarget()->getName() . ($showLongTargets ? ' [' . $event->getTarget()->getDescription() . ']' : '') . ':' . PHP_EOL;
            $this->printMessage($msg, $this->out, $event->getPriority());
        }
    }

    /**
     *  Fired when a target has finished. We don't need specific action on this
     *  event. So the methods are empty.
     *
     * @see   BuildEvent::getException()
     */
    public function targetFinished(BuildEvent $event)
    {
    }

    /**
     *  Fired when a task is started. We don't need specific action on this
     *  event. So the methods are empty.
     *
     * @see   BuildEvent::getTask()
     */
    public function taskStarted(BuildEvent $event)
    {
    }

    /**
     *  Fired when a task has finished. We don't need specific action on this
     *  event. So the methods are empty.
     *
     * @param BuildEvent $event The BuildEvent
     * @see   BuildEvent::getException()
     */
    public function taskFinished(BuildEvent $event)
    {
    }

    /**
     *  Print a message to the stdout.
     *
     * @see   BuildEvent::getMessage()
     */
    public function messageLogged(BuildEvent $event)
    {
        $priority = $event->getPriority();
        if ($priority <= $this->msgOutputLevel) {
            $msg = "";
            if ($event->getTask() !== null && !$this->emacsMode) {
                $name = $event->getTask();
                $name = $name->getTaskName();
                $msg = str_pad("[$name] ", self::LEFT_COLUMN_SIZE, " ", STR_PAD_LEFT);
            }

            $msg .= $event->getMessage();

            if ($priority != Project::MSG_ERR) {
                $this->printMessage($msg, $this->out, $priority);
            } else {
                $this->printMessage($msg, $this->err, $priority);
            }
        }
    }

    /**
     *  Formats a time micro int to human readable format.
     *
     * @param  int The time stamp
     * @return string
     */
    public static function formatTime($micros)
    {
        $seconds = $micros;
        $minutes = (int) floor($seconds / 60);
        if ($minutes >= 1) {
            return sprintf(
                "%1.0f minute%s %0.2f second%s",
                $minutes,
                ($minutes === 1 ? " " : "s "),
                $seconds - floor($seconds / 60) * 60,
                ($seconds % 60 === 1 ? "" : "s")
            );
        }

        return sprintf("%0.4f second%s", $seconds, ($seconds % 60 === 1 ? "" : "s"));
    }

    /**
     * Prints a message to console.
     *
     * @param  string                $message  The message to print.
     *                                         Should not be
     *                                         <code>null</code>.
     * @param  OutputStream|resource $stream   The stream to use for message printing.
     * @param  int                   $priority The priority of the message.
     *                                         (Ignored in this
     *                                         implementation.)
     * @throws IOException
     */
    protected function printMessage($message, OutputStream $stream, $priority)
    {
        $stream->write($message . PHP_EOL);
    }
}
