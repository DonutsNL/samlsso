<?php

declare(strict_types=1);

/**
 *  ------------------------------------------------------------------------
 *  samlSSO
 *
 *  samlSSO was inspired by the initial work of Derrick Smith's
 *  PhpSaml. This project's intend is to address some structural issues
 *  caused by the gradual development of GLPI and the broad amount of
 *  wishes expressed by the community.
 *
 *  Copyright (C) 2024 by Chris Gralike
 *  ------------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is part of samlSSO plugin for GLPI.
 *
 * samlSSO plugin is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * samlSSO is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with samlSSO. If not, see <http://www.gnu.org/licenses/> or
 * https://choosealicense.com/licenses/gpl-3.0/
 *
 * ------------------------------------------------------------------------
 *
 *  @package    samlSSO
 *  @version    1.4.1
 *  @author     Chris Gralike
 *  @copyright  Copyright (c) 2024 by Chris Gralike
 *  @license    GPLv3+
 *  @see        https://github.com/DonutsNL/samlSSO/readme.md
 *  @link       https://github.com/DonutsNL/samlSSO
 *  @since      1.4.0
 * ------------------------------------------------------------------------
 **/

namespace GlpiPlugin\Samlsso\Scim;

use Symfony\Component\HttpFoundation\Response;

/**
 * ScimFilterParser implements a bounded, local whitelist parser for RFC 7644 §3.4.2.2
 * filter expressions without external Composer grammar dependencies.
 *
 * Enforces hard upper bounds (<= 1024 bytes, <= 32 tokens, <= 4 parenthesis depth),
 * decodes JSON string literals via json_decode(..., JSON_THROW_ON_ERROR), normalizes
 * operators case-insensitively, and rejects unindexed attributes with HTTP 400 invalidFilter.
 *
 * @since 1.4.0
 */
final class ScimFilterParser
{
    /** @var int Maximum raw filter length in bytes. */
    public const MAX_FILTER_BYTES = 1024;

    /** @var int Maximum lexical token count. */
    public const MAX_TOKENS = 32;

    /** @var int Maximum parenthesis nesting depth. */
    public const MAX_DEPTH = 4;

    /** @var array<string, string> Whitelisted normalized User filter attributes. */
    private const USER_ATTR_WHITELIST = [
        'username'                      => 'userName',
        'externalid'                    => 'externalId',
        'id'                            => 'id',
        'active'                        => 'active',
        'displayname'                   => 'displayName',
        'name.givenname'                => 'givenName',
        'name.familyname'               => 'familyName',
        'emails'                        => 'email',
        'emails.value'                  => 'email',
        'emails[type eq "work"].value'  => 'email',
        'emails[primary eq true].value' => 'email',
        'employeenumber'                => 'employeeNumber',
    ];

    /** @var array<string, string> Whitelisted normalized Group filter attributes. */
    private const GROUP_ATTR_WHITELIST = [
        'displayname' => 'displayName',
        'externalid'  => 'externalId',
        'id'          => 'id',
    ];

    /**
     * Parse an RFC 7644 filter string into a validated conjunction AST of whitelisted clauses.
     *
     * @param string $filter       Raw filter query parameter.
     * @param string $resourceType Resource context ('User' or 'Group').
     * @return array<int, array{attr: string, op: string, value: mixed}>
     * @throws ScimException With HTTP 400 and scimType='invalidFilter' on invalid/unsupported filters.
     */
    public static function parse(string $filter, string $resourceType = 'User'): array
    {
        $trimmed = trim($filter);
        if ($trimmed === '') {
            return [];
        }

        if (strlen($trimmed) > self::MAX_FILTER_BYTES) {
            throw new ScimException(
                Response::HTTP_BAD_REQUEST,
                'SCIM filter exceeds maximum allowed length.',
                'invalidFilter'
            );
        }

        $tokens = self::tokenize($trimmed);
        if (count($tokens) > self::MAX_TOKENS) {
            throw new ScimException(
                Response::HTTP_BAD_REQUEST,
                'SCIM filter exceeds maximum allowed complexity.',
                'invalidFilter'
            );
        }

        $pos = 0;
        $clauses = self::parseExpression($tokens, $pos, 0, $resourceType);
        if ($pos !== count($tokens)) {
            throw new ScimException(
                Response::HTTP_BAD_REQUEST,
                'Unexpected trailing tokens in SCIM filter expression.',
                'invalidFilter'
            );
        }

        return $clauses;
    }

    /**
     * Extract exact-match pushdown hints (id, externalId, userName, email, displayName)
     * from a parsed conjunction AST to narrow parameterized SQL candidate queries.
     *
     * @param array<int, array{attr: string, op: string, value: mixed}> $clauses Parsed clauses.
     * @return array<string, string>
     */
    public static function buildPushdownHints(array $clauses): array
    {
        $hints = [];
        foreach ($clauses as $clause) {
            if ($clause['op'] === 'eq' && is_string($clause['value']) && $clause['value'] !== '') {
                $hints[$clause['attr']] = $clause['value'];
            }
        }
        return $hints;
    }

    /**
     * Evaluate a parsed conjunction AST against a serialized SCIM User or Group resource.
     *
     * @param array<int, array{attr: string, op: string, value: mixed}> $clauses  Parsed clauses.
     * @param array<string, mixed>                                      $resource Serialized SCIM resource.
     * @return bool
     */
    public static function matchesResource(array $clauses, array $resource): bool
    {
        foreach ($clauses as $clause) {
            if (!self::evaluateClause($clause, $resource)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Tokenize a SCIM filter expression into parentheses, attribute paths (including [...] value filters),
     * JSON string literals, booleans, numbers, and bare identifiers/operators.
     *
     * @param string $input Trimmed filter expression.
     * @return array<int, array{type: string, value: mixed}>
     * @throws ScimException On malformed string literal or unexpected character.
     */
    private static function tokenize(string $input): array
    {
        $tokens = [];
        $len = strlen($input);
        $i = 0;

        while ($i < $len) {
            $ch = $input[$i];
            if (ctype_space($ch)) {
                $i++;
                continue;
            }

            if ($ch === '(') {
                $tokens[] = ['type' => 'LPAREN', 'value' => '('];
                $i++;
                continue;
            }

            if ($ch === ')') {
                $tokens[] = ['type' => 'RPAREN', 'value' => ')'];
                $i++;
                continue;
            }

            if ($ch === '"') {
                [$decodedStr, $nextIdx] = self::consumeJsonString($input, $i);
                $tokens[] = ['type' => 'VALUE', 'value' => $decodedStr];
                $i = $nextIdx;
                continue;
            }

            [$word, $nextIdx] = self::consumeAttributeOrWord($input, $i);
            if ($word === '') {
                throw new ScimException(
                    Response::HTTP_BAD_REQUEST,
                    'Invalid character in SCIM filter expression.',
                    'invalidFilter'
                );
            }

            $lower = strtolower($word);
            if ($lower === 'true' || $lower === 'false') {
                $tokens[] = ['type' => 'VALUE', 'value' => ($lower === 'true')];
            } elseif ($lower === 'null') {
                $tokens[] = ['type' => 'VALUE', 'value' => null];
            } elseif (preg_match('/^-?\d+(\.\d+)?$/', $word) === 1) {
                $tokens[] = ['type' => 'VALUE', 'value' => $word];
            } else {
                $tokens[] = ['type' => 'WORD', 'value' => $word];
            }

            $i = $nextIdx;
        }

        return $tokens;
    }

    /**
     * Consume a JSON double-quoted string starting at $start and decode via json_decode.
     *
     * @param string $input Full filter input.
     * @param int    $start Opening quote byte offset.
     * @return array{0: string, 1: int}
     * @throws ScimException On unterminated or invalid JSON string literal.
     */
    private static function consumeJsonString(string $input, int $start): array
    {
        $len = strlen($input);
        $i = $start + 1;
        while ($i < $len) {
            if ($input[$i] === '\\') {
                $i += 2;
                continue;
            }
            if ($input[$i] === '"') {
                $rawLiteral = substr($input, $start, ($i - $start) + 1);
                try {
                    $decoded = json_decode($rawLiteral, true, 2, JSON_THROW_ON_ERROR);
                } catch (\JsonException $e) {
                    throw new ScimException(
                        Response::HTTP_BAD_REQUEST,
                        'Malformed string literal in SCIM filter.',
                        'invalidFilter'
                    );
                }
                if (!is_string($decoded)) {
                    throw new ScimException(
                        Response::HTTP_BAD_REQUEST,
                        'Malformed string literal in SCIM filter.',
                        'invalidFilter'
                    );
                }
                return [$decoded, $i + 1];
            }
            $i++;
        }

        throw new ScimException(
            Response::HTTP_BAD_REQUEST,
            'Unterminated string literal in SCIM filter.',
            'invalidFilter'
        );
    }

    /**
     * Consume an attribute path (including bracketed value-path expressions like emails[type eq "work"].value)
     * or an operator keyword.
     *
     * @param string $input Full filter input.
     * @param int    $start Start byte offset.
     * @return array{0: string, 1: int}
     * @throws ScimException On unterminated bracket expression.
     */
    private static function consumeAttributeOrWord(string $input, int $start): array
    {
        $len = strlen($input);
        $i = $start;
        $inBracket = false;
        $inQuote = false;

        while ($i < $len) {
            $ch = $input[$i];
            if ($inQuote) {
                if ($ch === '\\') {
                    $i += 2;
                    continue;
                }
                if ($ch === '"') {
                    $inQuote = false;
                }
                $i++;
                continue;
            }

            if ($inBracket) {
                if ($ch === '"') {
                    $inQuote = true;
                } elseif ($ch === ']') {
                    $inBracket = false;
                }
                $i++;
                continue;
            }

            if ($ch === '[') {
                $inBracket = true;
                $i++;
                continue;
            }

            if (ctype_space($ch) || $ch === '(' || $ch === ')' || $ch === '"') {
                break;
            }

            $i++;
        }

        if ($inBracket || $inQuote) {
            throw new ScimException(
                Response::HTTP_BAD_REQUEST,
                'Unterminated value-path bracket in SCIM filter.',
                'invalidFilter'
            );
        }

        return [substr($input, $start, $i - $start), $i];
    }

    /**
     * Parse one or more AND-connected filter terms recursively within bounded parenthesis depth.
     *
     * @param array<int, array{type: string, value: mixed}> $tokens       Token stream.
     * @param int                                           $pos          Current token index (by reference).
     * @param int                                           $depth        Current parenthesis nesting depth.
     * @param string                                        $resourceType Resource context ('User' or 'Group').
     * @return array<int, array{attr: string, op: string, value: mixed}>
     * @throws ScimException On invalid syntax, unsupported operator, or exceeded nesting depth.
     */
    private static function parseExpression(array $tokens, int &$pos, int $depth, string $resourceType): array
    {
        if ($depth > self::MAX_DEPTH) {
            throw new ScimException(
                Response::HTTP_BAD_REQUEST,
                'SCIM filter exceeds maximum parenthesis nesting depth.',
                'invalidFilter'
            );
        }

        $clauses = self::parseTerm($tokens, $pos, $depth, $resourceType);

        while ($pos < count($tokens) && $tokens[$pos]['type'] === 'WORD') {
            $conj = strtolower((string) $tokens[$pos]['value']);
            if ($conj === 'and') {
                $pos++;
                $right = self::parseTerm($tokens, $pos, $depth, $resourceType);
                $clauses = array_merge($clauses, $right);
                continue;
            }
            throw new ScimException(
                Response::HTTP_BAD_REQUEST,
                sprintf('Unsupported logical operator "%s" in SCIM filter; only "and" conjunctions are indexed.', (string) $tokens[$pos]['value']),
                'invalidFilter'
            );
        }

        return $clauses;
    }

    /**
     * Parse either a parenthesized sub-expression or a single attribute comparison / presence clause.
     *
     * @param array<int, array{type: string, value: mixed}> $tokens       Token stream.
     * @param int                                           $pos          Current token index (by reference).
     * @param int                                           $depth        Current parenthesis depth.
     * @param string                                        $resourceType Resource context.
     * @return array<int, array{attr: string, op: string, value: mixed}>
     * @throws ScimException On malformed clause or unwhitelisted attribute.
     */
    private static function parseTerm(array $tokens, int &$pos, int $depth, string $resourceType): array
    {
        if ($pos >= count($tokens)) {
            throw new ScimException(Response::HTTP_BAD_REQUEST, 'Unexpected end of SCIM filter.', 'invalidFilter');
        }

        if ($tokens[$pos]['type'] === 'LPAREN') {
            $pos++;
            $inner = self::parseExpression($tokens, $pos, $depth + 1, $resourceType);
            if ($pos >= count($tokens) || $tokens[$pos]['type'] !== 'RPAREN') {
                throw new ScimException(Response::HTTP_BAD_REQUEST, 'Unclosed parenthesis in SCIM filter.', 'invalidFilter');
            }
            $pos++;
            return $inner;
        }

        if ($tokens[$pos]['type'] !== 'WORD') {
            throw new ScimException(Response::HTTP_BAD_REQUEST, 'Expected attribute name in SCIM filter.', 'invalidFilter');
        }

        $rawAttr = (string) $tokens[$pos]['value'];
        if (strtolower($rawAttr) === 'not') {
            throw new ScimException(Response::HTTP_BAD_REQUEST, 'Logical "not" operator is not supported in SCIM filter.', 'invalidFilter');
        }
        $canonicalAttr = self::normalizeWhitelistedAttribute($rawAttr, $resourceType);
        $pos++;

        if ($pos >= count($tokens) || $tokens[$pos]['type'] !== 'WORD') {
            throw new ScimException(Response::HTTP_BAD_REQUEST, 'Expected comparison operator in SCIM filter.', 'invalidFilter');
        }

        $op = strtolower((string) $tokens[$pos]['value']);
        $pos++;

        if ($op === 'pr') {
            return [['attr' => $canonicalAttr, 'op' => 'pr', 'value' => null]];
        }

        if (!in_array($op, ['eq', 'ne', 'co', 'sw', 'ew'], true)) {
            throw new ScimException(
                Response::HTTP_BAD_REQUEST,
                sprintf('Unsupported comparison operator "%s" in SCIM filter.', $op),
                'invalidFilter'
            );
        }

        if ($pos >= count($tokens) || !in_array($tokens[$pos]['type'], ['VALUE', 'WORD'], true)) {
            throw new ScimException(Response::HTTP_BAD_REQUEST, 'Missing comparison value in SCIM filter.', 'invalidFilter');
        }

        $val = $tokens[$pos]['value'];
        $pos++;

        return [['attr' => $canonicalAttr, 'op' => $op, 'value' => $val]];
    }

    /**
     * Normalize URN prefixes and bracket whitespace on an attribute path and validate against the resource whitelist.
     *
     * @param string $rawAttr      Raw attribute path token.
     * @param string $resourceType Resource context ('User' or 'Group').
     * @return string Canonical attribute identifier.
     * @throws ScimException On unwhitelisted attribute.
     */
    private static function normalizeWhitelistedAttribute(string $rawAttr, string $resourceType): string
    {
        $lower = strtolower(trim($rawAttr));
        $prefixes = [
            strtolower(ScimDiscoveryService::SCHEMA_CORE_USER) . ':',
            strtolower(ScimDiscoveryService::SCHEMA_ENTERPRISE_USER) . ':',
            strtolower(ScimDiscoveryService::SCHEMA_CORE_GROUP) . ':',
        ];
        foreach ($prefixes as $prefix) {
            if (str_starts_with($lower, $prefix)) {
                $lower = substr($lower, strlen($prefix));
                break;
            }
        }

        $lower = (string) preg_replace('/\s+/', ' ', $lower);
        $whitelist = strcasecmp($resourceType, 'Group') === 0
            ? self::GROUP_ATTR_WHITELIST
            : self::USER_ATTR_WHITELIST;

        if (!isset($whitelist[$lower])) {
            throw new ScimException(
                Response::HTTP_BAD_REQUEST,
                sprintf('Filtering on attribute "%s" is not supported.', $rawAttr),
                'invalidFilter'
            );
        }

        return $whitelist[$lower];
    }

    /**
     * Evaluate a single whitelisted AST clause against a serialized SCIM resource.
     *
     * @param array{attr: string, op: string, value: mixed} $clause   Single parsed clause.
     * @param array<string, mixed>                          $resource Serialized SCIM resource.
     * @return bool
     */
    private static function evaluateClause(array $clause, array $resource): bool
    {
        $candidates = self::extractCandidateValues($clause['attr'], $resource);
        $op = $clause['op'];
        $target = $clause['value'];

        if ($op === 'pr') {
            foreach ($candidates as $cand) {
                if ($cand !== null && $cand !== '') {
                    return true;
                }
            }
            return false;
        }

        if ($op === 'ne') {
            foreach ($candidates as $cand) {
                if (self::scalarMatches('eq', $cand, $target)) {
                    return false;
                }
            }
            return true;
        }

        foreach ($candidates as $cand) {
            if (self::scalarMatches($op, $cand, $target)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extract scalar values for a canonical whitelisted attribute from a serialized SCIM resource.
     *
     * @param string               $canonicalAttr Canonical attribute name.
     * @param array<string, mixed> $resource      Serialized SCIM resource.
     * @return array<int, mixed>
     */
    private static function extractCandidateValues(string $canonicalAttr, array $resource): array
    {
        switch ($canonicalAttr) {
            case 'id':
            case 'externalId':
            case 'userName':
            case 'displayName':
            case 'active':
                return array_key_exists($canonicalAttr, $resource) ? [$resource[$canonicalAttr]] : [];
            case 'givenName':
                return isset($resource['name']['givenName']) ? [$resource['name']['givenName']] : [];
            case 'familyName':
                return isset($resource['name']['familyName']) ? [$resource['name']['familyName']] : [];
            case 'employeeNumber':
                $ent = $resource[ScimDiscoveryService::SCHEMA_ENTERPRISE_USER] ?? [];
                return isset($ent['employeeNumber']) ? [$ent['employeeNumber']] : [];
            case 'email':
                $vals = [];
                if (isset($resource['emails']) && is_array($resource['emails'])) {
                    foreach ($resource['emails'] as $entry) {
                        if (is_array($entry) && isset($entry['value'])) {
                            $vals[] = (string) $entry['value'];
                        }
                    }
                }
                return $vals;
            default:
                return [];
        }
    }

    /**
     * Perform RFC 7644 case-insensitive string / boolean comparison on a single candidate value.
     *
     * @param string $op        Comparison operator ('eq', 'co', 'sw', 'ew').
     * @param mixed  $candidate Candidate attribute value.
     * @param mixed  $target    Filter literal value.
     * @return bool
     */
    private static function scalarMatches(string $op, mixed $candidate, mixed $target): bool
    {
        if (is_bool($candidate) || is_bool($target)) {
            if ($op !== 'eq') {
                return false;
            }
            try {
                return ScimAttributeMapper::parseBoolean($candidate) === ScimAttributeMapper::parseBoolean($target);
            } catch (ScimException $e) {
                return false;
            }
        }

        if ($candidate === null || $target === null) {
            return $op === 'eq' && $candidate === $target;
        }

        $candLower = mb_strtolower((string) $candidate);
        $targetLower = mb_strtolower((string) $target);

        return match ($op) {
            'eq'    => $candLower === $targetLower,
            'co'    => $targetLower !== '' && str_contains($candLower, $targetLower),
            'sw'    => $targetLower !== '' && str_starts_with($candLower, $targetLower),
            'ew'    => $targetLower !== '' && str_ends_with($candLower, $targetLower),
            default => false,
        };
    }
}
