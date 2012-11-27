# php-multi-processes

It can help you to run your code in more isolated processes (threads).

## Use

    $manager = new ThreadManager('Manager 1');
    $manager->startThread(function() { echo "Starting thread\n"; sleep(2); "Thread end\n"; });
    sleep(1);
    echo "Waiting for childs\n";
    $manager->waitForThreads();
