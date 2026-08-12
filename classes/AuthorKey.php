<?php

/**
 * @file plugins/generic/recommendByAuthor/classes/AuthorKey.php
 *
 * Copyright (c) 2014-2025 Simon Fraser University
 * Copyright (c) 2003-2025 John Willinsky
 * Copyright (c) 2026 OJSBR (https://ojsbr.com)
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class AuthorKey
 *
 * @brief Turns an author into the keys under which their articles are found.
 */

namespace APP\plugins\generic\recommendByAuthor\classes;

use Normalizer;

class AuthorKey
{
    /** Keys are stored in a varchar(160); longer names are hashed instead of cut. */
    private const MAX_LENGTH = 160;

    /**
     * The key for a name, or null when there is not enough of a name to match on.
     *
     * The original plugin compares the stored strings as they are, so "José
     * Silva" and "Jose  Silva" are different authors. Case, accents and
     * repeated spaces are folded here, which finds articles the original
     * misses, while still requiring the whole given and family name to agree --
     * the false positives of the original ("Silva" matching every Silva) are
     * not made any more likely.
     */
    public static function fromName(?string $givenName, ?string $familyName): ?string
    {
        $given = self::fold((string) $givenName);
        $family = self::fold((string) $familyName);
        if ($given === '' && $family === '') {
            return null;
        }

        return self::fit('n:' . $given . '|' . $family);
    }

    /**
     * The key for an ORCID, or null when the value is not one.
     *
     * ORCIDs are recorded as a URL (https://orcid.org/0000-0002-1825-0097), and
     * an author may have typed one by hand; only the identifier is kept.
     */
    public static function fromOrcid(?string $orcid): ?string
    {
        if (!preg_match('~(\d{4}-\d{4}-\d{4}-\d{3}[\dX])~i', (string) $orcid, $matches)) {
            return null;
        }

        return 'o:' . strtoupper($matches[1]);
    }

    /**
     * Lowercase, without diacritics, single-spaced and without the punctuation
     * that separates initials, so that "J. P. Silva" and "J P Silva" agree.
     */
    private static function fold(string $value): string
    {
        $value = Normalizer::normalize($value, Normalizer::FORM_D) ?: $value;
        $value = preg_replace('/\p{Mn}+/u', '', $value);
        $value = preg_replace('/[.,;:_\'"()\[\]]+/u', ' ', $value);
        $value = preg_replace('/\s+/u', ' ', $value);

        return trim(mb_strtolower($value, 'UTF-8'));
    }

    /**
     * Keeps the key inside the column. A name long enough to overflow is
     * represented by its hash, which still matches itself and nothing else.
     */
    private static function fit(string $key): string
    {
        return strlen($key) <= self::MAX_LENGTH ? $key : 'h:' . hash('sha256', $key);
    }
}
