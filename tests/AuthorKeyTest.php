<?php

/**
 * @file tests/AuthorKeyTest.php
 *
 * Copyright (c) 2014-2025 Simon Fraser University
 * Copyright (c) 2003-2025 John Willinsky
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class AuthorKeyTest
 *
 * @brief Unit tests for the author identity keys.
 *
 * These are the rules that decide whether two records are the same person, so
 * they are tested on their own, without a database. Run with the OJS test
 * suite:
 *
 *   lib/pkp/lib/vendor/bin/phpunit plugins/generic/recommendByAuthor/tests
 */

namespace APP\plugins\generic\recommendByAuthor\tests;

use APP\plugins\generic\recommendByAuthor\classes\AuthorKey;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PKP\tests\PKPTestCase;

#[CoversClass(AuthorKey::class)]
class AuthorKeyTest extends PKPTestCase
{
    /**
     * Names that must fold to the same key: the same person, recorded by
     * different people on different days.
     */
    public static function sameNameProvider(): array
    {
        return [
            'accents' => [['João', 'Silva'], ['Joao', 'Silva']],
            'case' => [['JOÃO', 'SILVA'], ['joão', 'silva']],
            'trailing space' => [['Leandro Barbosa ', ' Teixeira'], ['Leandro Barbosa', 'Teixeira']],
            'double space' => [['Leandro  Barbosa', 'Teixeira'], ['Leandro Barbosa', 'Teixeira']],
            'initials punctuation' => [['J. P.', 'Silva'], ['J P', 'Silva']],
            'tabs' => [["Ana\tMaria", 'Sá'], ['Ana Maria', 'Sa']],
            'cedilla' => [['Conceição', 'Assunção'], ['Conceicao', 'Assuncao']],
        ];
    }

    #[DataProvider('sameNameProvider')]
    public function testSpellingsOfTheSamePersonShareOneKey(array $first, array $second): void
    {
        $this->assertSame(
            AuthorKey::fromName($first[0], $first[1]),
            AuthorKey::fromName($second[0], $second[1])
        );
        $this->assertNotNull(AuthorKey::fromName($first[0], $first[1]));
    }

    /**
     * Names that must NOT fold together. Recall is worth nothing if it invents
     * co-authorship.
     */
    public static function differentNameProvider(): array
    {
        return [
            'different surname' => [['Ana', 'Silva'], ['Ana', 'Silveira']],
            'different given name' => [['Ana', 'Silva'], ['Anabela', 'Silva']],
            'swapped' => [['Silva', 'Ana'], ['Ana', 'Silva']],
            'initial is not the name' => [['A.', 'Silva'], ['Ana', 'Silva']],
        ];
    }

    #[DataProvider('differentNameProvider')]
    public function testDifferentPeopleKeepDifferentKeys(array $first, array $second): void
    {
        $this->assertNotSame(
            AuthorKey::fromName($first[0], $first[1]),
            AuthorKey::fromName($second[0], $second[1])
        );
    }

    /**
     * The bug this plugin refuses to reproduce: the original matches nameless
     * authors against every other nameless author in the database.
     */
    public static function namelessProvider(): array
    {
        return [
            'both empty' => ['', ''],
            'both null' => [null, null],
            'spaces' => ['   ', '  '],
            'tab' => ["\t", "\n"],
            'punctuation only' => ['.', ','],
        ];
    }

    #[DataProvider('namelessProvider')]
    public function testANamelessAuthorHasNoKey(?string $given, ?string $family): void
    {
        $this->assertNull(AuthorKey::fromName($given, $family));
    }

    public function testHalfANameIsStillAKey(): void
    {
        $this->assertNotNull(AuthorKey::fromName('Ana', ''));
        $this->assertNotNull(AuthorKey::fromName('', 'Silva'));
        $this->assertNotSame(AuthorKey::fromName('Ana', ''), AuthorKey::fromName('', 'Ana'));
    }

    public function testALongNameIsHashedSoItStillFitsTheColumn(): void
    {
        $key = AuthorKey::fromName(str_repeat('a', 300), str_repeat('b', 300));

        $this->assertStringStartsWith('h:', $key);
        $this->assertLessThanOrEqual(160, strlen($key), 'the key must fit varchar(160)');
        $this->assertSame($key, AuthorKey::fromName(str_repeat('a', 300), str_repeat('b', 300)));
    }

    public static function orcidProvider(): array
    {
        return [
            'https url' => ['https://orcid.org/0000-0002-1825-0097', 'o:0000-0002-1825-0097'],
            'http url' => ['http://orcid.org/0000-0002-1825-0097', 'o:0000-0002-1825-0097'],
            'bare' => ['0000-0002-1825-0097', 'o:0000-0002-1825-0097'],
            'with spaces' => ['  0000-0002-1825-0097  ', 'o:0000-0002-1825-0097'],
            'checksum X' => ['0000-0002-1825-009X', 'o:0000-0002-1825-009X'],
            'lowercase checksum' => ['0000-0002-1825-009x', 'o:0000-0002-1825-009X'],
        ];
    }

    #[DataProvider('orcidProvider')]
    public function testAnOrcidIsReducedToItsIdentifier(string $input, string $expected): void
    {
        $this->assertSame($expected, AuthorKey::fromOrcid($input));
    }

    public static function notAnOrcidProvider(): array
    {
        return [
            'empty' => [''],
            'null' => [null],
            'prose' => ['not an orcid'],
            'too short' => ['0000-0002-1825'],
            'url without id' => ['https://orcid.org/'],
            'digits only' => ['0000000218250097'],
        ];
    }

    #[DataProvider('notAnOrcidProvider')]
    public function testAnythingThatIsNotAnOrcidHasNoKey(?string $input): void
    {
        $this->assertNull(AuthorKey::fromOrcid($input));
    }

    public function testNameKeysAndOrcidKeysCannotCollide(): void
    {
        $this->assertStringStartsWith('n:', AuthorKey::fromName('0000-0002-1825-0097', ''));
        $this->assertStringStartsWith('o:', AuthorKey::fromOrcid('0000-0002-1825-0097'));
        $this->assertNotSame(
            AuthorKey::fromName('0000-0002-1825-0097', ''),
            AuthorKey::fromOrcid('0000-0002-1825-0097')
        );
    }
}
