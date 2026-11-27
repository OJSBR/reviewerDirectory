<?php

/**
 * @file plugins/generic/reviewerDirectory/tests/LocaleFilesTest.php
 *
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class LocaleFilesTest
 *
 * @brief Translations. A key missing from a locale is rendered as ##key##, so an
 *        incomplete file is worse than none.
 */

namespace APP\plugins\generic\reviewerDirectory\tests;

use PKP\tests\PKPTestCase;

class LocaleFilesTest extends PKPTestCase
{
    /** Locale codes shipped by the plugin, as OJS 3.5 names them. */
    public const LOCALES = [
        'ar', 'az', 'bg', 'ca', 'cs', 'da', 'de', 'el', 'en', 'es', 'eu',
        'fa', 'fi', 'fr', 'fr_CA', 'gl', 'hu', 'hy', 'id', 'it', 'ja', 'ka',
        'mk', 'ms', 'nb_NO', 'nl', 'pl', 'pt', 'pt_BR', 'ro', 'ru', 'sl', 'sr_Latn',
        'sv', 'tr', 'uk', 'vi', 'zh_Hans',
    ];

    /** Locales reviewed by a fluent speaker; every entry of the others is marked fuzzy. */
    /** Locales reviewed in full by a fluent speaker: no entry may be fuzzy. */
    public const REVIEWED = ['en', 'pt', 'pt_BR', 'es'];

    public const MASTER = 'en';

    /** Keys translated before 5.0.1.0, reviewed then: a text equal to the English one is a real translation. */
    public const EARLIER_KEYS = [
        'displayName', 'description', 'openDirectory', 'searchPlaceholder', 'rating', 'lastAssigned', 'lastCompleted',
        'orcidVerified', 'columns', 'export', 'tabDirectory', 'tabNominata', 'nominataIntro', 'dateFrom', 'dateTo',
        'issue', 'allIssues', 'generate', 'nominataEmpty', 'reviewsInScope', 'submissions', 'firstDate', 'lastDate',
    ];


    protected function localeDir(): string
    {
        return dirname(__DIR__) . '/locale';
    }

    /** @return array<string, PoFile> */
    protected function files(): array
    {
        $files = [];
        foreach (self::LOCALES as $locale) {
            $path = $this->localeDir() . "/{$locale}/locale.po";
            if (is_file($path)) {
                $files[$locale] = new PoFile($path);
            }
        }
        return $files;
    }

    public function testShipsExactlyTheLocaleCodesOfThisLine(): void
    {
        $dirs = array_map('basename', glob($this->localeDir() . '/*', GLOB_ONLYDIR) ?: []);
        sort($dirs);
        $expected = self::LOCALES;
        sort($expected);

        // A code from another OJS line (fr vs fr_FR, pt vs pt_PT) silently never loads.
        $this->assertSame($expected, $dirs);
        $this->assertCount(count(self::LOCALES), $this->files());
    }

    public function testEveryLocaleHasExactlyTheKeysOfTheMaster(): void
    {
        $files = $this->files();
        $master = array_keys($files[self::MASTER]->entries);
        $this->assertGreaterThan(100, count($master));

        foreach ($files as $locale => $file) {
            $this->assertSame($master, array_keys($file->entries), "Keys of {$locale} differ from " . self::MASTER . '.');
        }
    }

    public function testEveryKeyTheCodeUsesExists(): void
    {
        $sources = '';
        $root = dirname(__DIR__);
        foreach (array_merge(glob($root . '/*.php'), glob($root . '/classes/*.php'), glob($root . '/classes/*/*.php'), glob($root . '/templates/*.tpl'), glob($root . '/templates/*/*.tpl'), glob($root . '/js/*.js')) as $file) {
            $sources .= file_get_contents($file);
        }
        preg_match_all('/plugins\.generic\.reviewerDirectory\.[a-zA-Z0-9_.]*[a-zA-Z0-9_]/', $sources, $m);
        $keys = array_keys($this->files()[self::MASTER]->entries);

        foreach (array_unique($m[0]) as $key) {
            if (str_ends_with($key, '.')) {
                continue;
            }
            $this->assertTrue(in_array($key, $keys, true) || $this->isKeyPrefix($key, $keys), "{$key} is used but not translated.");
        }

        $p = 'plugins.generic.reviewerDirectory.';
        foreach (\APP\plugins\generic\reviewerDirectory\classes\ReviewerStats::SITUATIONS as $id) {
            $this->assertContains($p . 'situation.' . $id, $keys);
            $this->assertContains($p . 'situation.' . $id . '.description', $keys);
        }
        foreach (\APP\plugins\generic\reviewerDirectory\classes\DirectoryFilters::GROUPS as $id) {
            $this->assertContains($p . 'group.' . $id, $keys);
            $this->assertContains($p . 'group.' . $id . '.description', $keys);
        }
        foreach (\APP\plugins\generic\reviewerDirectory\classes\DirectoryFilters::ORCID as $id) {
            $this->assertContains($p . 'filter.orcid.' . $id, $keys);
        }
        $handler = (string) file_get_contents($root . '/ReviewerDirectoryHandler.php');
        $this->assertSame(1, preg_match('/\$own = \[(.*?)\];/s', $handler, $own));
        preg_match_all("/'([a-zA-Z.]+)'/", $own[1], $names);
        foreach ($names[1] as $name) {
            $this->assertContains($p . $name, $keys);
        }
        // Every text the page reads is sent by the handler.
        preg_match_all("/'([a-zA-Z.]+)' => '([a-zA-Z]+)'/", $handler, $renamed);
        preg_match_all("/'([a-zA-Z]+)' => __\\('/", $handler, $core);
        $sent = array_merge($names[1], $renamed[1], $core[1]);
        $script = (string) file_get_contents($root . '/js/reviewerDirectory.js');
        preg_match_all("/\\bt\\.([a-zA-Z]+)\\b|\\bt\\[\\\\'([a-zA-Z.]+)\\\\'\\]/", $script, $used);
        foreach (array_unique(array_filter(array_merge($used[1], $used[2]))) as $name) {
            $this->assertContains($name, $sent, "The page uses t.{$name}, which the handler does not send.");
        }
    }

    /** A key built at runtime from a prefix ("...status." . $name). */
    protected function isKeyPrefix(string $key, array $keys): bool
    {
        foreach ($keys as $known) {
            if (str_starts_with($known, $key . '.')) {
                return true;
            }
        }
        return false;
    }

    public function testNoTranslationIsEmpty(): void
    {
        foreach ($this->files() as $locale => $file) {
            foreach ($file->entries as $key => $value) {
                $this->assertNotEmpty(trim($value), "Empty translation for {$key} in {$locale}.");
            }
        }
    }

    /** Translations from the original authors keep the headers they were published with. */
    public const UPSTREAM_HEADERS = [];

    public function testHeaderDeclaresTheLocaleAndTheTeam(): void
    {
        foreach ($this->files() as $locale => $file) {
            $this->assertStringContainsString("Language: {$locale}\n", $file->header, "Wrong Language header in {$locale}.");
            if (in_array($locale, self::UPSTREAM_HEADERS, true)) {
                continue;
            }
            $this->assertStringContainsString("Last-Translator: OJSBR\n", $file->header, "Missing Last-Translator in {$locale}.");
            $this->assertStringContainsString("Language-Team: OJSBR\n", $file->header, "Missing Language-Team in {$locale}.");
        }
    }

    public function testUnreviewedTextIsMarkedFuzzy(): void
    {
        $files = $this->files();
        $master = $files[self::MASTER]->entries;
        foreach ($files as $locale => $file) {
            foreach ($file->entries as $key => $value) {
                if (in_array($locale, self::REVIEWED, true)) {
                    $this->assertFalse($file->fuzzy[$key] ?? false, "Fuzzy entry {$key} in {$locale}, reviewed in full.");
                } elseif ($value === $master[$key] && !in_array(substr($key, strlen('plugins.generic.reviewerDirectory.')), self::EARLIER_KEYS, true) && !preg_match('/^[\\d\\W]*$|^(CSV|Total|ORCID)/u', $value)) {
                    // Text still in English in another language waits for a speaker, flagged.
                    $this->assertTrue($file->fuzzy[$key] ?? false, "Untranslated entry {$key} in {$locale} is not marked fuzzy.");
                }
            }
        }
    }

    public function testPlaceholdersAreTheSameInEveryLocale(): void
    {
        $files = $this->files();
        $placeholders = function (string $value): array {
            preg_match_all('/\{\$[a-zA-Z0-9_]+\}/', $value, $m);
            $found = array_values(array_unique($m[0]));
            sort($found);
            return $found;
        };
        foreach ($files[self::MASTER]->entries as $key => $master) {
            foreach ($files as $locale => $file) {
                $this->assertSame($placeholders($master), $placeholders($file->entries[$key]), "Placeholders of {$key} in {$locale}.");
                // "count" is reserved by the translator (plural rules).
                $this->assertStringNotContainsString('{$count}', $file->entries[$key], "{$key} uses the reserved {\$count} in {$locale}.");
            }
        }
    }

    public function testTheDescriptionCarriesNoCredit(): void
    {
        foreach ($this->files() as $locale => $file) {
            foreach ($file->entries as $key => $value) {
                if (str_ends_with($key, '.description') && substr_count($key, '.') <= 3) {
                    $this->assertStringNotContainsString('OJSBR', $value, "Credit in {$key} ({$locale}).");
                }
            }
        }
    }
}
