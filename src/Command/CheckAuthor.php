<?php

/**
 * This file is part of contao-community-alliance/build-system-tool-author-validation.
 *
 * (c) Contao Community Alliance <https://c-c-a.org>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * This project is provided in good faith and hope to be usable by anyone.
 *
 * @package    contao-community-alliance/build-system-tool-author-validation
 * @author     Tristan Lins <tristan.lins@bit3.de>
 * @author     Christian Schiffler <c.schiffler@cyberspectrum.de>
 * @copyright  Contao Community Alliance <https://c-c-a.org>
 * @link       https://github.com/contao-community-alliance/build-system-tool-author-validation
 * @license    https://github.com/contao-community-alliance/build-system-tool-author-validation/blob/master/LICENSE MIT
 * @filesource
 */

namespace ContaoCommunityAlliance\BuildSystem\Tool\AuthorValidation\Command;

use ContaoCommunityAlliance\BuildSystem\Tool\AuthorValidation\AuthorExtractor;
use ContaoCommunityAlliance\BuildSystem\Tool\AuthorValidation\AuthorExtractor\GitAuthorExtractor;
use ContaoCommunityAlliance\BuildSystem\Tool\AuthorValidation\AuthorExtractor\PhpDocAuthorExtractor;
use ContaoCommunityAlliance\BuildSystem\Tool\AuthorValidation\AuthorListComparator;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;

/**
 * Class to check the mentioned authors.
 *
 * @package ContaoCommunityAlliance\BuildSystem\Tool\AuthorValidation\Command
 */
class CheckAuthor extends Command
{
    /**
     * {@inheritDoc}
     */
    protected function configure()
    {
        $this
            ->setName('ccabs:tools:check-author')
            ->setDescription('Check that all authors are mentioned in each file.')
            ->addOption(
                'php-files',
                null,
                InputOption::VALUE_NONE,
                'Validate @author annotations in PHP files'
            )
            ->addOption(
                'composer',
                null,
                InputOption::VALUE_NONE,
                'Validate authors in composer.json'
            )
            ->addOption(
                'bower',
                null,
                InputOption::VALUE_NONE,
                'Validate authors in bower.json'
            )
            ->addOption(
                'packages',
                null,
                InputOption::VALUE_NONE,
                'Validate authors in packages.json'
            )
            ->addOption(
                'ignore',
                null,
                (InputOption::VALUE_IS_ARRAY | InputOption::VALUE_OPTIONAL),
                'authors to ignore (format: "John Doe <j.doe@acme.org>"',
                array()
            )
            ->addOption(
                'diff',
                null,
                InputOption::VALUE_NONE,
                'create output in diff format instead of mentioning what\'s missing/superfluous.'
            )
            ->addArgument(
                'dir',
                InputArgument::OPTIONAL,
                'The directory to start searching, must be a git repository or a subdir in a git repository.',
                '.'
            );
    }

    /**
     * Find PHP files, read the authors and validate against the git log of each file.
     *
     * @param string               $dir        The directory to search files in.
     *
     * @param string[]             $ignores    The authors to be ignored from the git repository.
     *
     * @param OutputInterface      $output     The output.
     *
     * @param AuthorListComparator $comparator The comparator performing the comparisons.
     *
     * @return bool
     */
    private function validatePhpAuthors($dir, $ignores, OutputInterface $output, AuthorListComparator $comparator)
    {
        $finder = new Finder();

        $finder->in($dir)->notPath('/vendor/')->files()->name('*.php');

        $invalidates = false;

        /** @var \SplFileInfo[] $finder */
        foreach ($finder as $file) {
            /** @var \SplFileInfo $file */
            $phpExtractor = new PhpDocAuthorExtractor($dir, $file->getPathname(), $output);
            $gitExtractor = new GitAuthorExtractor($file->getPathname(), $output);
            $gitExtractor->setIgnoredAuthors($ignores);

            $invalidates = !$comparator->compare($phpExtractor, $gitExtractor) || $invalidates;
        }

        return !$invalidates;
    }

    /**
     * Create all source extractors as specified on the command line.
     *
     * @param InputInterface  $input  The input interface.
     *
     * @param OutputInterface $output The output interface to use for logging.
     *
     * @param string          $dir    The base directory.
     *
     * @return AuthorExtractor[]
     */
    protected function createSourceExtractors(InputInterface $input, OutputInterface $output, $dir)
    {
        // Remark: a plugin system would be really nice here, so others could simply hook themselves into the checking.
        $extractors = array();
        foreach (array(
            'bower' =>
                'ContaoCommunityAlliance\BuildSystem\Tool\AuthorValidation\AuthorExtractor\BowerAuthorExtractor',
            'composer' =>
                'ContaoCommunityAlliance\BuildSystem\Tool\AuthorValidation\AuthorExtractor\ComposerAuthorExtractor',
            'packages' =>
                'ContaoCommunityAlliance\BuildSystem\Tool\AuthorValidation\AuthorExtractor\NodeAuthorExtractor',
                 ) as $option => $class) {
            if ($input->getOption($option)) {
                $extractors[$option] = new $class($dir, $output);
            }
        }

        return $extractors;
    }

    /**
     * Process the given extractors.
     *
     * @param AuthorExtractor[]    $extractors The extractors.
     *
     * @param AuthorExtractor      $reference  The extractor to use as reference.
     *
     * @param AuthorListComparator $comparator The comparator to use.
     *
     * @return bool
     */
    private function handleExtractors($extractors, AuthorExtractor $reference, AuthorListComparator $comparator)
    {
        $failed = false;

        foreach ($extractors as $extractor) {
            $failed = !$comparator->compare($extractor, $reference) || $failed;
        }

        return $failed;
    }

    /**
     * {@inheritDoc}
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $diff       = $input->getOption('diff');
        $ignores    = $input->getOption('ignore');
        $dir        = realpath($input->getArgument('dir'));
        $error      = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $extractors = $this->createSourceExtractors($input, $error, $dir);

        if (empty($extractors) && !$input->getOption('php-files')) {
            $error->writeln('<error>You must select at least one validation to run!</error>');
            $error->writeln('check-author.php [--php-files] [--composer] [--bower] [--packages]');

            return 1;
        }

        $failed = false;

        $comparator = new AuthorListComparator($error);
        $comparator->shallGeneratePatches($diff);

        if (!empty($extractors)) {
            $gitExtractor = new GitAuthorExtractor($dir, $error);
            $gitExtractor->setIgnoredAuthors($ignores);
            $failed = $this->handleExtractors($extractors, $gitExtractor, $comparator);
        }

        // Finally check the php files.

        $failed = ($input->getOption('php-files') && !$this->validatePhpAuthors($dir, $ignores, $error, $comparator))
                  || $failed;

        if ($failed && $diff) {
            $output->writeln($comparator->getPatchSet());
        }

        return $failed ? 1 : 0;
    }
}
