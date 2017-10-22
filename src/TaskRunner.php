<?php
declare(strict_types = 1);

namespace GitIterator;

use GitIterator\Formatter\Formatter;
use GitIterator\Helper\CommandRunner;
use GitIterator\Helper\Git;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

/**
 * @author Matthieu Napoli <matthieu@mnapoli.fr>
 */
class TaskRunner
{
    /**
     * @var Git
     */
    private $git;

    /**
     * @var Filesystem
     */
    private $filesystem;

    /**
     * @var CommandRunner
     */
    private $commandRunner;

    /**
     * @var Application
     */
    private $application;

    public function __construct(Git $git, Filesystem $filesystem, CommandRunner $commandRunner, Application $application)
    {
        $this->git = $git;
        $this->filesystem = $filesystem;
        $this->commandRunner = $commandRunner;
        $this->application = $application;
    }

    public function run(string $url, array $tasks = null, string $format = null, InputInterface $input, ConsoleOutputInterface $output)
    {
        // TODO default parameter value?
        $format = $format ?: 'csv';

        $directory = $this->createTemporaryDirectory();

        $this->printInfo("Cloning $url in $directory", $output);
        $this->git->clone($url, $directory);

        $configuration = $this->loadConfiguration($tasks);

        // Get the list of commits
        $commits = $this->git->getCommitList($directory, 'master');
        $this->printInfo(sprintf('Iterating through %d commits', count($commits)), $output);

        $data = $this->processCommits($commits, $directory, $configuration['tasks']);

        $this->formatAndOutput($format, $output, $configuration, $data);

        $this->printInfo('Done', $output);

        /** @var QuestionHelper $helper */
        $helper = $this->application->getHelperSet()->get('question');
        $question = new ConfirmationQuestion("<comment>Delete directory $directory? <info>[Y/n]</info></comment>", true);
        if ($helper->ask($input, $output, $question)) {
            $this->printInfo("Deleting $directory", $output);
            $this->filesystem->remove($directory);
        } else {
            $this->printInfo("Not deleting $directory", $output);
        }
    }

    private function processCommits($commits, $directory, array $tasks) : \Generator
    {
        foreach ($commits as $commit) {
            $this->git->checkoutCommit($directory, $commit);
            yield $this->processDirectory($commit, $directory, $tasks);
        }
    }

    private function processDirectory(string $commit, string $directory, array $tasks) : array
    {
        $timestamp = $this->git->getCommitTimestamp($directory, $commit);
        $data = [
            'commit' => $commit,
            'date' => date('Y-m-d H:i:s', $timestamp),
        ];
        foreach ($tasks as $taskName => $taskCommand) {
            $taskResult = $this->commandRunner->runInDirectory($directory, $taskCommand);
            $data[$taskName] = $taskResult;
        }
        return $data;
    }

    private function formatAndOutput(string $format, ConsoleOutputInterface $output, array $configuration, $data)
    {
        $format = $format ?: 'csv';
        $formatterClass = sprintf('GitIterator\Formatter\%sFormatter', ucfirst($format));
        /** @var Formatter $formatter */
        $formatter = new $formatterClass;
        $data = $formatter->format($configuration, $data);
        foreach ($data as $line) {
            $output->writeln($line);
        }
    }

    private function loadConfiguration(array $tasks = null) : array
    {
        if (! file_exists('gitstats.yml')) {
            throw new \Exception('Configuration file "gitstats.yml" missing');
        }
        $configuration = Yaml::parse(file_get_contents('gitstats.yml'));

        if ($tasks && !empty($configuration['tasks'])) {
            $configuration['tasks'] = array_intersect_key($configuration['tasks'], array_flip($tasks));
        }

        return $configuration;
    }

    /**
     * @return string Directory path.
     */
    private function createTemporaryDirectory() : string
    {
        $temporaryFile = tempnam(sys_get_temp_dir(), 'gitstats_');

        // Turn the temporary file into a temporary directory
        $this->filesystem->remove($temporaryFile);
        $this->filesystem->mkdir($temporaryFile);

        return $temporaryFile;
    }

    private function printInfo($message, ConsoleOutputInterface $output)
    {
        $output->getErrorOutput()->writeln("<comment>$message</comment>");
    }
}
