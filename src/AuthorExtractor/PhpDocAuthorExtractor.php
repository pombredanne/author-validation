<?php

/**
 * This file is part of phpcq/author-validation.
 *
 * (c) 2014 Christian Schiffler, Tristan Lins
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * This project is provided in good faith and hope to be usable by anyone.
 *
 * @package    phpcq/author-validation
 * @author     Christian Schiffler <c.schiffler@cyberspectrum.de>
 * @author     Tristan Lins <tristan@lins.io>
 * @author     David Molineus <david.molineus@netzmacht.de>
 * @copyright  2014-2016 Christian Schiffler <c.schiffler@cyberspectrum.de>, Tristan Lins <tristan@lins.io>
 * @license    https://github.com/phpcq/author-validation/blob/master/LICENSE MIT
 * @link       https://github.com/phpcq/author-validation
 * @filesource
 */

namespace PhpCodeQuality\AuthorValidation\AuthorExtractor;

/**
 * Extract the author information from a phpDoc file doc block.
 */
class PhpDocAuthorExtractor extends AbstractPatchingAuthorExtractor
{
    /**
     * The file to work on.
     *
     * @var string
     */
    protected $filePath;

    /**
     * {@inheritDoc}
     */
    protected function buildFinder()
    {
        return parent::buildFinder()->name('*.php');
    }

    /**
     * {@inheritDoc}
     */
    protected function doExtract($path)
    {
        if (!preg_match_all('/.*@author\s+(.*)\s*/', $this->getBuffer($path), $matches, PREG_OFFSET_CAPTURE)) {
            return array();
        }

        $mentionedAuthors = array();
        foreach ($matches[1] as $match) {
            $mentionedAuthors[] = $match[0];
        }

        return $mentionedAuthors;
    }

    /**
     * {@inheritDoc}
     */
    public function getBuffer($path, $authors = null)
    {
        if (!is_file($path)) {
            return '';
        }

        // 4k ought to be enough of a file header for anyone (I hope).
        $content = file_get_contents($path, null, null, null, 4096);
        $closing = strpos($content, '*/');
        if ($closing === false) {
            return '';
        }

        $docBlock = substr($content, 0, ($closing + 2));

        if ($authors) {
            return $this->setAuthors($docBlock, $this->calculateUpdatedAuthors($path, $authors));
        }

        return $docBlock;
    }

    /**
     * Set the author information in doc block.
     *
     * @param array $docBlock The doc block.
     *
     * @param array $authors  The authors to set in the doc block.
     *
     * @return array The updated doc block.
     */
    protected function setAuthors($docBlock, $authors)
    {
        $newAuthors = $authors;
        $lines      = explode("\n", $docBlock);
        $lastAuthor = 0;
        $indention  = ' * @author     ';
        $cleaned    = array();

        foreach ($lines as $number => $line) {
            if (strpos($line, '@author') === false) {
                continue;
            }
            $lastAuthor = $number;
            $suffix     = trim(substr($line, (strpos($line, '@author') + 7)));
            $indention  = substr($line, 0, (strlen($line) - strlen($suffix)));

            $index = $this->searchAuthor($line, $newAuthors);

            // Obsolete entry, remove it.
            if (false !== $index) {
                unset($newAuthors[$index]);
                $lines[$number] = null;
                $cleaned[]      = $number;
            }
        }

        if (!empty($newAuthors)) {
            // Fill the gaps we just made.
            foreach ($cleaned as $number) {
                $lines[$number] = $indention . array_shift($newAuthors);
            }

            if ($lastAuthor == 0) {
                $lastAuthor = (count($lines) - 2);
            }
            while ($author = array_shift($newAuthors)) {
                $lines[$lastAuthor++] = $indention . $author;
            }
        }

        return implode("\n", array_filter($lines));
    }

    /**
     * Search the author in "line" in the passed array and return the index of the match or false if none matches.
     *
     * @param string   $line    The author to search for.
     *
     * @param string[] $authors The author list to search in.
     *
     * @return false|int
     */
    private function searchAuthor($line, $authors)
    {
        foreach ($authors as $index => $author) {
            list($name, $email) = explode(' <', $author);

            $name  = trim($name);
            $email = trim(substr($email, 0, -1));
            if ((strpos($line, $name) !== false) && (strpos($line, $email) !== false)) {
                unset($authors[$index]);
                return $index;
            }
        }

        return false;
    }
}
