<?php declare(strict_types=1);
/*
 * This file is part of PHPWEB Copy/Paste Detector (PHPWEBCPD).
 *
 * (c) Enrique Somolinos <enrique.somolinos@gmail.com>
 *     Sebastian Bergmann <sebastian@phpunit.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace PHPWEBCPD\CLI;

use EnriqueSomolinos\PHPWEBCPD\StrategyFactory;
use SebastianBergmann\FinderFacade\FinderFacade;
use PHPWEBCPD\Detector\Detector;
use PHPWEBCPD\Detector\Strategy\DefaultStrategy;
use PHPWEBCPD\Log\PMD;
use PHPWEBCPD\Log\Text;
use SebastianBergmann\Timer\Timer;
use Symfony\Component\Console\Command\Command as AbstractCommand;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class Command extends AbstractCommand
{
    /**
     * Configures the current command.
     */
    protected function configure(): void
    {
        $this->setName('phpwebcpd')
             ->setDefinition(
                 [
                     new InputArgument(
                         'values',
                         InputArgument::IS_ARRAY,
                         'Files and directories to analyze'
                     ),
                 ]
             )
             ->addOption(
                 'names',
                 null,
                 InputOption::VALUE_REQUIRED,
                 'A comma-separated list of file names to check',
                 ['*.php', '*.twig', '*.js', '*.css', '*.scss']
             )
             ->addOption(
                 'names-exclude',
                 null,
                 InputOption::VALUE_REQUIRED,
                 'A comma-separated list of file names to exclude',
                 []
             )
             ->addOption(
                 'regexps-exclude',
                 null,
                 InputOption::VALUE_REQUIRED,
                 'A comma-separated list of paths regexps to exclude (example: "#var/.*_tmp#")',
                 []
             )
             ->addOption(
                 'exclude',
                 null,
                 InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                 'Exclude a directory from code analysis (must be relative to source)'
             )
             ->addOption(
                 'log-pmd',
                 null,
                 InputOption::VALUE_REQUIRED,
                 'Write result in PMD-CPD XML format to file'
             )
             ->addOption(
                 'min-lines',
                 null,
                 InputOption::VALUE_REQUIRED,
                 'Minimum number of identical lines',
                 5
             )
             ->addOption(
                 'min-tokens',
                 null,
                 InputOption::VALUE_REQUIRED,
                 'Minimum number of identical tokens',
                 70
             )
             ->addOption(
                 'fuzzy',
                 null,
                 InputOption::VALUE_NONE,
                 'Fuzz variable names'
             )
             ->addOption(
                 'progress',
                 null,
                 InputOption::VALUE_NONE,
                 'Show progress bar'
             );
    }

    /**
     * Executes the current command.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $names = $this->handleCSVOption($input, 'names');
        $files = [];
        foreach ($names as $name) {
            $finder = new FinderFacade(
                $input->getArgument('values'),
                $input->getOption('exclude'),
                array($name),
                $this->handleCSVOption($input, 'names-exclude'),
                $this->handleCSVOption($input, 'regexps-exclude')
            );
            $files[$name] = $finder->findFiles();
        }

        if (empty($files)) {
            $output->writeln('No files found to scan');

            return 0;
        }

        $progressBar = null;

        if ($input->getOption('progress')) {
            $totalFiles = 0;
            foreach ($files as $file) {
                $totalFiles += sizeof($file);
            }
            $progressBar = new ProgressBar($output, $totalFiles);
            $progressBar->start();
        }

        $clones = [];
        foreach ($files as $extension => $file) {
            $strategy = StrategyFactory::getStrategy($extension);
            $detector = new Detector($strategy, $progressBar);
            $quiet    = $output->getVerbosity() == OutputInterface::VERBOSITY_QUIET;

            $clones[$extension] = $detector->copyPasteDetection(
                $files[$extension],
                (int) $input->getOption('min-lines'),
                (int) $input->getOption('min-tokens'),
                (bool) $input->getOption('fuzzy')
            );

        }

        if ($input->getOption('progress')) {
            $progressBar->finish();
            $output->writeln("\n");
        }

        if (!$quiet) {
            $printer = new Text;
            foreach ($clones as $extension => $clone) {
                $printer->printResult($output, $clone, $extension);
            }
            unset($printer);
        }

        $logPmd = $input->getOption('log-pmd');

        if ($logPmd) {
            $pmd = new PMD($logPmd);
            foreach ($clones as $clone) {
                $pmd->processClones($clone);
            }
            unset($pmd);
        }

        if (!$quiet) {
            print Timer::resourceUsage() . "\n";
        }

        foreach ($clones as $extensionClones) {
            if (\count($extensionClones->getClones()) > 0) {
                return 1;
            }
        }

        return 0;
    }

    private function handleCSVOption(InputInterface $input, string $option): array
    {
        $result = $input->getOption($option);

        if (!\is_array($result)) {
            $result = \explode(',', $result);

            \array_map('trim', $result);
        }

        return $result;
    }
}
