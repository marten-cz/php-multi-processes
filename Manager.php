<?php

namespace Threading;

// Tick need to be declared because of the PCNTL signal handler
declare(ticks = 1);

class Manager
{

	/**
	 * @var string Name of the manager for identification
	 */
	private $name;

	/**
	 * @var resource Name of the manager for identification
	 */
	protected $msgqueue;

	/**
	 * @var bool Enable or disable threading
	 */
	private $threadsEnabled = TRUE;

	/**
	 * @var int  Maximum allowed number of threads
	 */
	private $maxThreads = 1;

	
	/**
	 * Construct the manager
	 *
	 * @param string|NULL $name Manager identification
	 */
	public function __construct($name = 'ThreadManager')
	{
		if (!extension_loaded('pcntl'))
		{
			throw new \InvalidStateException('PCNTL extension is not loaded');
		}

		$this->name = $name;
		$queueId = mt_rand(0, 64000);
		$this->msgqueue = msg_get_queue($queueId, 0666);
		pcntl_signal(SIGCHLD, array($this, "childSignalHandler"));
	}


	/**
	 * Handle child signals
	 *
	 * @param int $signo The signal number
	 * @param int $pid Forked child process number
	 * @param int $statuc Status
	 *
	 * @return bool TRUE
	 */
	public function childSignalHandler($signo, $pid = NULL, $status = NULL)
	{
		// If no pid is provided, that means we're getting the signal from the system.  Let's figure out
		// which child process ended
		if(!$pid)
		{
			$pid = pcntl_waitpid(-1, $status, WNOHANG);
		}

		// Make sure we get all of the exited children
		while($pid > 0)
		{
			$exitCode = pcntl_wexitstatus($status);
			if($exitCode != 0)
			{
				echo "$pid exited with status ".$exitCode."\n";
			}
			unset($this->currentJobs[$pid]);
			$pid = pcntl_waitpid(-1, $status, WNOHANG);
		}

		return TRUE;
	}

	/**
	 * Start anonymous function in new process
	 *
	 * The process is isolated from the parent, if you want to comunicate or send any information to
	 * the parent you need to use message queue.
	 *
	 * @param callable $callback Callback function
	 *
	 * @see http://php.net/manual/en/function.msg-get-queue.php
	 * @return void
	 */
	public function startThread($callback)
	{
		if (!$this->threadsEnabled)
		{
			return call_user_func($callback);
		}

		while (TRUE)
		{
			$currentqueue = msg_stat_queue($this->msgqueue);
			if ($currentqueue['msg_qnum'] <= $this->maxThreads)
			{
				break;
			}
		}

		$pid = pcntl_fork();
		if ($pid == -1)
		{
			die('Could not fork');
		}
		elseif (!$pid)
		{
			$myPid = getmypid();
			if (!msg_send($this->msgqueue, 1, posix_getpid(), true, true, $errmsg))
			{
				exit(1);
			};

			call_user_func($callback);
			exit(0);
		}
	}


	/**
	 * Wait for all processes to end
	 *
	 * If the process is running kill it immediately.
	 */
	public function killThreads()
	{
		$this->endThreads(TRUE);
	}


	/**
	 * Wait for all processes to end
	 *
	 * If the process is running wait for it to finish.
	 */
	public function waitForThreads()
	{
		$this->endThreads(FALSE);
	}


	/**
	 * Wait for all processes to end
	 *
	 * @param bool $kill If TRUE kill all running processes
	 *
	 * @return bool TRUE when the processes were ended
	 */
	private function endThreads($kill = FALSE)
	{
		if (!$this->threadsEnabled)
		{
			return TRUE;
		}

		$currentqueue = msg_stat_queue($this->msgqueue);
		$n = $currentqueue['msg_qnum']; // number of messages (number of kids to terminate)
		if ($n > 0)
		{
			for ($i = 0; $i < $n; $i++)
			{
				// pop the kid's PID from the IPC message queue
				if (!msg_receive ($this->msgqueue, 1, $msg_type, 16384, $msg, true, 0, $msg_error))
				{
					echo "MSG_RECV ERROR: $errmsg \n"; // something has gone wrong
				}
				else
				{
					$kill && posix_kill($msg, SIGINT);
					pcntl_waitpid($msg, $tmpstat, 0); // terminate kid for real.
				}
			}
		}

		return TRUE;
	}


}
