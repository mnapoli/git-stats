<?php
declare(strict_types = 1);

use GitIterator\Helper\Git;
use GitIterator\TaskRunner;
use Silly\Edition\PhpDi\Application;
use Symfony\Component\Filesystem\Filesystem;

foreach ([__DIR__ . '/../../autoload.php', __DIR__ . '/../vendor/autoload.php', __DIR__ . '/vendor/autoload.php'] as $file) {
    if (file_exists($file)) {
        define('COMPOSER_INSTALL', $file);
        break;
    }
}
if (!defined('COMPOSER_INSTALL')) {
    echo 'Composer dependencies could not be found';
    die(1);
}

require COMPOSER_INSTALL;

$app = new Application();

$app->command('run [directory] [tasks]* [--format=]', [TaskRunner::class, 'run']);
$app->command('run-once [directory] [tasks]* [--format=]', [TaskRunner::class, 'runOnce']);

$app->command('clear', function (Filesystem $filesystem, Git $git) {
    $repositoryDirectory = __DIR__ . '/repository';
    $gitLock = $repositoryDirectory . '/.git/HEAD.lock';
    if ($filesystem->exists($gitLock)) {
        $filesystem->remove($gitLock);
    }
    $git->reset($repositoryDirectory, 'master', true);
});

$app->run();