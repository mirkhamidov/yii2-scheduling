<?php

namespace omnilight\scheduling;

use Closure;
use Cron\CronExpression;
use DateTime;
use DateTimeZone;
use GuzzleHttp\Client as HttpClient;
use LogicException;
use Symfony\Component\Process\Process;
use yii\base\Application;
use yii\base\Component;
use yii\base\InvalidCallException;
use yii\base\InvalidConfigException;
use yii\mail\MailerInterface;
use yii\mutex\Mutex;
use yii\mutex\FileMutex;

class Event extends Component
{
    const EVENT_BEFORE_RUN = 'beforeRun';

    const EVENT_AFTER_RUN = 'afterRun';

    /**
     * Command string
     * @var string
     */
    public string $command;

    /**
     * The cron expression representing the event's frequency.
     *
     * @var string
     */
    protected string $_expression = '* * * * *';

    /**
     * The timezone the date should be evaluated on.
     *
     * @var DateTimeZone|string
     */
    protected DateTimeZone|string $_timezone;

    /**
     * The user the command should run as.
     *
     * @var string|null
     */
    protected ?string $_user = null;

    /**
     * The filter callback.
     *
     * @var Closure
     */
    protected Closure $_filter;

    /**
     * The reject callback.
     *
     * @var Closure
     */
    protected Closure $_reject;

    /**
     * The location that output should be sent to.
     *
     * @var string|null
     */
    protected ?string $_output = null;

    /**
     * The string for redirection.
     *
     * @var array|string
     */
    protected array|string $_redirect = ' > ';

    /**
     * The array of callbacks to be run after the event is finished.
     *
     * @var array
     */
    protected array $_afterCallbacks = [];

    /**
     * The human readable description of the event.
     *
     * @var string
     */
    protected string $_description;

    /**
     * The mutex implementation.
     *
     * @var Mutex
     */
    protected Mutex $_mutex;

    /**
     * Decide if errors will be displayed.
     *
     * @var bool
     */
    protected bool $_omitErrors = false;

    /**
     * Create a new event instance.
     *
     * @param Mutex $mutex
     * @param string $command
     * @param array $config
     */
    public function __construct(Mutex $mutex, string $command, array $config = [])
    {
        $this->command = $command;
        $this->_mutex = $mutex;
        $this->_output = $this->getDefaultOutput();
        parent::__construct($config);
    }

    /**
     * Run the given event.
     * @param Application $app
     */
    public function run(Application $app)
    {
        $this->trigger(self::EVENT_BEFORE_RUN);
        if (count($this->_afterCallbacks) > 0) {
            $this->runCommandInForeground($app);
        } else {
            $this->runCommandInBackground($app);
        }
        $this->trigger(self::EVENT_AFTER_RUN);
    }

    /**
     * Get the mutex name for the scheduled command.
     *
     * @return string
     */
    protected function mutexName(): string
    {
        return 'framework/schedule-' . sha1($this->_expression . $this->command);
    }

    /**
     * Run the command in the foreground.
     *
     * @param Application $app
     * @throws InvalidConfigException
     */
    protected function runCommandInForeground(Application $app)
    {
        (new Process(
            trim($this->buildCommand(), '& '), dirname($app->request->getScriptFile()), null, null, null
        ))->run();
        $this->callAfterCallbacks($app);
    }

    /**
     * Build the comand string.
     *
     * @return string
     */
    public function buildCommand(): string
    {
        $command = $this->command . $this->_redirect . $this->_output . (($this->_omitErrors) ? ' 2>&1 &' : '');
        return $this->_user ? 'sudo -u ' . $this->_user . ' ' . $command : $command;
    }

    /**
     * Call all of the "after" callbacks for the event.
     *
     * @param Application $app
     */
    protected function callAfterCallbacks(Application $app)
    {
        foreach ($this->_afterCallbacks as $callback) {
            call_user_func($callback, $app);
        }
    }

    /**
     * Run the command in the background using exec.
     *
     * @param Application $app
     * @throws InvalidConfigException
     */
    protected function runCommandInBackground(Application $app)
    {
        chdir(dirname($app->request->getScriptFile()));
        exec($this->buildCommand());
    }

    /**
     * Determine if the given event should run based on the Cron expression.
     *
     * @param Application $app
     * @return bool
     */
    public function isDue(Application $app): bool
    {
        return $this->expressionPasses() && $this->filtersPass($app);
    }

    /**
     * Determine if the Cron expression passes.
     *
     * @return bool
     */
    protected function expressionPasses()
    {
        $date = new DateTime('now');
        if ($this->_timezone) {
            $date->setTimezone($this->_timezone);
        }
        return (new CronExpression($this->_expression))->isDue($date);
    }

    /**
     * Determine if the filters pass for the event.
     *
     * @param Application $app
     * @return bool
     */
    protected function filtersPass(Application $app): bool
    {
        if ($this->_filter && !call_user_func($this->_filter, $app) ||
            $this->_reject && call_user_func($this->_reject, $app)
        ) {
            return false;
        }
        return true;
    }

    /**
     * Schedule the event to run hourly.
     *
     * @return $this
     */
    public function hourly(): self
    {
        return $this->cron('0 * * * *');
    }

    /**
     * The Cron expression representing the event's frequency.
     *
     * @param  string $expression
     * @return $this
     */
    public function cron(string $expression): self
    {
        $this->_expression = $expression;
        return $this;
    }

    /**
     * Schedule the event to run daily.
     *
     * @return $this
     */
    public function daily(): self
    {
        return $this->cron('0 0 * * *');
    }

    /**
     * Schedule the command at a given time.
     *
     * @param  string $time
     * @return $this
     */
    public function at(string $time): self
    {
        return $this->dailyAt($time);
    }

    /**
     * Schedule the event to run daily at a given time (10:00, 19:30, etc).
     *
     * @param  string $time
     * @return $this
     */
    public function dailyAt(string $time): self
    {
        $segments = explode(':', $time);
        return $this->spliceIntoPosition(2, (int)$segments[0])
            ->spliceIntoPosition(1, count($segments) == 2 ? (int)$segments[1] : '0');
    }

    /**
     * Splice the given value into the given position of the expression.
     *
     * @param  int $position
     * @param  string $value
     * @return Event
     */
    protected function spliceIntoPosition(int $position, string $value): Event
    {
        $segments = explode(' ', $this->_expression);
        $segments[$position - 1] = $value;
        return $this->cron(implode(' ', $segments));
    }

    /**
     * Schedule the event to run twice daily.
     *
     * @return $this
     */
    public function twiceDaily(): self
    {
        return $this->cron('0 1,13 * * *');
    }

    /**
     * Schedule the event to run only on weekdays.
     *
     * @return $this
     */
    public function weekdays(): self
    {
        return $this->spliceIntoPosition(5, '1-5');
    }

    /**
     * Schedule the event to run only on Mondays.
     *
     * @return $this
     */
    public function mondays(): self
    {
        return $this->days(1);
    }

    /**
     * Set the days of the week the command should run on.
     *
     * @param  array|int $days
     * @return $this
     */
    public function days(array|int $days): self
    {
        $days = is_array($days) ? $days : func_get_args();
        return $this->spliceIntoPosition(5, implode(',', $days));
    }

    /**
     * Schedule the event to run only on Tuesdays.
     *
     * @return $this
     */
    public function tuesdays(): self
    {
        return $this->days(2);
    }

    /**
     * Schedule the event to run only on Wednesdays.
     *
     * @return $this
     */
    public function wednesdays(): self
    {
        return $this->days(3);
    }

    /**
     * Schedule the event to run only on Thursdays.
     *
     * @return $this
     */
    public function thursdays(): self
    {
        return $this->days(4);
    }

    /**
     * Schedule the event to run only on Fridays.
     *
     * @return $this
     */
    public function fridays(): self
    {
        return $this->days(5);
    }

    /**
     * Schedule the event to run only on Saturdays.
     *
     * @return $this
     */
    public function saturdays(): self
    {
        return $this->days(6);
    }

    /**
     * Schedule the event to run only on Sundays.
     *
     * @return $this
     */
    public function sundays(): self
    {
        return $this->days(0);
    }

    /**
     * Schedule the event to run weekly.
     *
     * @return $this
     */
    public function weekly(): self
    {
        return $this->cron('0 0 * * 0');
    }

    /**
     * Schedule the event to run weekly on a given day and time.
     *
     * @param  int $day
     * @param  string $time
     * @return $this
     */
    public function weeklyOn(int $day, string $time = '0:0'): self
    {
        $this->dailyAt($time);
        return $this->spliceIntoPosition(5, $day);
    }

    /**
     * Schedule the event to run monthly.
     *
     * @return $this
     */
    public function monthly(): self
    {
        return $this->cron('0 0 1 * *');
    }

    /**
     * Schedule the event to run yearly.
     *
     * @return $this
     */
    public function yearly(): self
    {
        return $this->cron('0 0 1 1 *');
    }

    /**
     * Schedule the event to run every minute.
     *
     * @return $this
     */
    public function everyMinute(): self
    {
        return $this->cron('* * * * *');
    }

    /**
     * Schedule the event to run every N minutes.
     *
     * @param int|string $minutes
     * @return $this
     */
    public function everyNMinutes(int|string $minutes): self
    {
        return $this->cron('*/'.$minutes.' * * * *');
    }

    /**
     * Schedule the event to run every five minutes.
     *
     * @return $this
     */
    public function everyFiveMinutes(): self
    {
        return $this->everyNMinutes(5);
    }

    /**
     * Schedule the event to run every ten minutes.
     *
     * @return $this
     */
    public function everyTenMinutes(): self
    {
        return $this->everyNMinutes(10);
    }

    /**
     * Schedule the event to run every thirty minutes.
     *
     * @return $this
     */
    public function everyThirtyMinutes(): self
    {
        return $this->cron('0,30 * * * *');
    }

    /**
     * Set the timezone the date should be evaluated on.
     *
     * @param  DateTimeZone|string $timezone
     * @return $this
     */
    public function timezone(DateTimeZone|string $timezone): self
    {
        $this->_timezone = $timezone;
        return $this;
    }

    /**
     * Set which user the command should run as.
     *
     * @param  string $user
     * @return $this
     */
    public function user(string $user): self
    {
        $this->_user = $user;
        return $this;
    }

    /**
     * Set if errors should be displayed
     *
     * @param  bool $omitErrors
     * @return $this
     */
    public function omitErrors(bool $omitErrors = false): self
    {
        $this->_omitErrors = $omitErrors;
        return $this;
    }

    /**
     * Do not allow the event to overlap each other.
     *
     * @return $this
     */
    public function withoutOverlapping(): self
    {
        return $this->then(function() {
            $this->_mutex->release($this->mutexName());
        })->skip(function() {
            return !$this->_mutex->acquire($this->mutexName());
        });
    }

    /**
     * Allow the event to only run on one server for each cron expression.
     *
     * @return $this
     * @throws InvalidConfigException
     */
    public function onOneServer(): self
    {
        if ($this->_mutex instanceof FileMutex) {
            throw new InvalidConfigException('You must config mutex in the application component, except the FileMutex.');
        }

        return $this->withoutOverlapping();
    }

    /**
     * Register a callback to further filter the schedule.
     *
     * @param  Closure $callback
     * @return $this
     */
    public function when(Closure $callback): self
    {
        $this->_filter = $callback;
        return $this;
    }

    /**
     * Register a callback to further filter the schedule.
     *
     * @param  Closure $callback
     * @return $this
     */
    public function skip(Closure $callback): self
    {
        $this->_reject = $callback;
        return $this;
    }

    /**
     * Send the output of the command to a given location.
     *
     * @param  string $location
     * @return $this
     */
    public function sendOutputTo(string $location): self
    {
        $this->_redirect = ' > ';
        $this->_output = $location;
        return $this;
    }

    /**
     * Append the output of the command to a given location.
     *
     * @param  string $location
     * @return $this
     */
    public function appendOutputTo(string $location): self
    {
        $this->_redirect = ' >> ';
        $this->_output = $location;
        return $this;
    }

    /**
     * E-mail the results of the scheduled operation.
     *
     * @param  array $addresses
     * @return $this
     *
     * @throws LogicException
     */
    public function emailOutputTo(array $addresses): self
    {
        if (is_null($this->_output) || $this->_output == $this->getDefaultOutput()) {
            throw new InvalidCallException("Must direct output to a file in order to e-mail results.");
        }
        $addresses = is_array($addresses) ? $addresses : func_get_args();
        return $this->then(function (Application $app) use ($addresses) {
            $this->emailOutput($app->mailer, $addresses);
        });
    }

    /**
     * Register a callback to be called after the operation.
     *
     * @param  Closure $callback
     * @return $this
     */
    public function then(Closure $callback): self
    {
        $this->_afterCallbacks[] = $callback;
        return $this;
    }

    /**
     * E-mail the output of the event to the recipients.
     *
     * @param MailerInterface $mailer
     * @param array $addresses
     * @return Event
     */
    protected function emailOutput(MailerInterface $mailer, array $addresses): self
    {
        $textBody = file_get_contents($this->_output);

        if (trim($textBody) != '' ) {
            $mailer->compose()
                ->setTextBody($textBody)
                ->setSubject($this->getEmailSubject())
                ->setTo($addresses)
                ->send();
        }
    }

    /**
     * Get the e-mail subject line for output results.
     *
     * @return string
     */
    protected function getEmailSubject(): string
    {
        if ($this->_description) {
            return 'Scheduled Job Output (' . $this->_description . ')';
        }
        return 'Scheduled Job Output';
    }

    /**
     * Register a callback to the ping a given URL after the job runs.
     *
     * @param  string $url
     * @return $this
     */
    public function thenPing(string $url): self
    {
        return $this->then(function () use ($url) {
            (new HttpClient)->get($url);
        });
    }

    /**
     * Set the human-friendly description of the event.
     *
     * @param  string $description
     * @return $this
     */
    public function description(string $description): self
    {
        $this->_description = $description;
        return $this;
    }

    /**
     * Get the summary of the event for display.
     *
     * @return string
     */
    public function getSummaryForDisplay(): string
    {
        if (is_string($this->_description)) return $this->_description;
        return $this->buildCommand();
    }

    /**
     * Get the Cron expression for the event.
     *
     * @return string
     */
    public function getExpression(): string
    {
        return $this->_expression;
    }

    public function getDefaultOutput(): string
    {
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            return 'NUL';
        } else {
            return '/dev/null';
        }
    }
}
