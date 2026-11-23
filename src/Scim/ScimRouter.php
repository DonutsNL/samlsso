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

use GlpiPlugin\Samlsso\Config\ConfigEntity;
use GlpiPlugin\Samlsso\Controller\SamlSsoController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Toolbox;

/**
 * ScimRouter authenticates incoming SCIM 2.0 HTTP requests and dispatches them
 * across discovery, /Users, and /Groups handlers while sandboxing exceptions into
 * sanitized RFC 7644 JSON Error responses.
 *
 * @since 1.4.0
 */
final class ScimRouter
{
    /** @var array<int, string> Allowed HTTP methods on SCIM endpoints. */
    private const ALLOWED_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

    /**
     * Authenticate and dispatch a SCIM v2 HTTP request.
     *
     * @param Request $request  Incoming Symfony HTTP request.
     * @param int     $idpId    Target IdP configuration ID from route.
     * @param string  $scimPath Sub-path under /front/scim/v2/{idpId}.
     * @return Response
     */
    public function dispatch(Request $request, int $idpId, string $scimPath = ''): Response
    {
        try {
            $method = strtoupper($request->getMethod());
            if (!in_array($method, self::ALLOWED_METHODS, true)) {
                throw new ScimException(
                    Response::HTTP_METHOD_NOT_ALLOWED,
                    sprintf('HTTP method %s is not allowed on SCIM endpoints.', $method),
                    null,
                    ['Allow' => implode(', ', self::ALLOWED_METHODS)]
                );
            }

            $authenticator = new ScimAuthenticator();
            $configEntity = $authenticator->authenticate($request, $idpId);

            $baseScimUrl = $this->resolveBaseScimUrl($request, $idpId);
            $groupPolicy = (string) ($configEntity->getField(ConfigEntity::SCIM_GROUP_POLICY) ?: 'create_scoped');
            $trimmedPath = trim($scimPath, '/');
            $segments = $trimmedPath === '' ? [] : explode('/', $trimmedPath, 3);

            if (empty($segments)) {
                return $this->requireGet($method, static fn(): Response => ScimResponseFactory::resource(
                    ScimDiscoveryService::getServiceProviderConfig($baseScimUrl)
                ));
            }

            $root = strtolower($segments[0]);

            if ($root === 'serviceproviderconfig' && count($segments) === 1) {
                return $this->requireGet($method, static fn(): Response => ScimResponseFactory::resource(
                    ScimDiscoveryService::getServiceProviderConfig($baseScimUrl)
                ));
            }

            if ($root === 'resourcetypes') {
                if (count($segments) === 1) {
                    return $this->requireGet($method, static function () use ($baseScimUrl, $groupPolicy): Response {
                        $types = ScimDiscoveryService::getResourceTypes($baseScimUrl, $groupPolicy);
                        return ScimResponseFactory::listResponse($types, count($types), 1);
                    });
                }
                if (count($segments) === 2) {
                    return $this->requireGet($method, static function () use ($segments, $baseScimUrl, $groupPolicy): Response {
                        $found = ScimDiscoveryService::getResourceTypeById(
                            rawurldecode($segments[1]),
                            $baseScimUrl,
                            $groupPolicy
                        );
                        if ($found === null) {
                            throw new ScimException(Response::HTTP_NOT_FOUND, 'SCIM ResourceType not found.');
                        }
                        return ScimResponseFactory::resource($found);
                    });
                }
            }

            if ($root === 'schemas') {
                if (count($segments) === 1) {
                    return $this->requireGet($method, static function () use ($baseScimUrl, $groupPolicy): Response {
                        $schemas = ScimDiscoveryService::getSchemas($baseScimUrl, $groupPolicy);
                        return ScimResponseFactory::listResponse($schemas, count($schemas), 1);
                    });
                }
                if (count($segments) === 2) {
                    return $this->requireGet($method, static function () use ($segments, $baseScimUrl, $groupPolicy): Response {
                        $found = ScimDiscoveryService::getSchemaById(
                            rawurldecode($segments[1]),
                            $baseScimUrl,
                            $groupPolicy
                        );
                        if ($found === null) {
                            throw new ScimException(Response::HTTP_NOT_FOUND, 'SCIM Schema not found.');
                        }
                        return ScimResponseFactory::resource($found);
                    });
                }
            }

            if ($root === 'bulk') {
                throw new ScimException(
                    Response::HTTP_NOT_IMPLEMENTED,
                    'SCIM /Bulk operations are not supported by this Service Provider.'
                );
            }

            if ($root === 'users') {
                return $this->dispatchUsers($request, $method, $segments, $configEntity, $baseScimUrl);
            }

            if ($root === 'groups') {
                return $this->dispatchGroups($request, $method, $segments, $configEntity, $baseScimUrl);
            }

            throw new ScimException(Response::HTTP_NOT_FOUND, 'Requested SCIM endpoint does not exist.');
        } catch (ScimException $e) {
            return ScimResponseFactory::fromException($e);
        } catch (Throwable $e) {
            if (class_exists('Toolbox') && method_exists('Toolbox', 'logInFile')) {
                Toolbox::logInFile(
                    PLUGIN_NAME . PLUGIN_SAMLSSO_LOGEVENTS,
                    sprintf('Unhandled SCIM exception for IdP #%d: %s' . "\n", $idpId, $e->getMessage())
                );
            }
            return ScimResponseFactory::error(
                Response::HTTP_INTERNAL_SERVER_ERROR,
                'Internal SCIM provisioning error.'
            );
        }
    }

    /**
     * Dispatch /Users, /Users/.search, and /Users/{id} operations.
     *
     * @param Request            $request      Incoming HTTP request.
     * @param string             $method       Uppercase HTTP verb.
     * @param array<int, string> $segments     URL path segments.
     * @param ConfigEntity       $configEntity Verified IdP configuration.
     * @param string             $baseScimUrl  Canonical SCIM base URL.
     * @return Response
     * @throws ScimException
     */
    private function dispatchUsers(
        Request $request,
        string $method,
        array $segments,
        ConfigEntity $configEntity,
        string $baseScimUrl
    ): Response {
        $service = new ScimUserService();

        if (count($segments) === 1) {
            if ($method === 'GET') {
                $page = $service->listUsers($configEntity, $request->query->all(), $baseScimUrl);
                return ScimResponseFactory::listResponse($page['resources'], $page['total'], $page['startIndex']);
            }
            if ($method === 'POST') {
                $body = ScimPatchProcessor::decodeJsonBody((string) $request->getContent());
                $created = $service->createUser($configEntity, $body, $baseScimUrl);
                $loc = (string) ($created['meta']['location'] ?? ($baseScimUrl . '/Users/' . $created['id']));
                return ScimResponseFactory::resource($created, Response::HTTP_CREATED, $loc);
            }
            throw new ScimException(
                Response::HTTP_METHOD_NOT_ALLOWED,
                'Allowed methods on /Users are GET and POST.',
                null,
                ['Allow' => 'GET, POST']
            );
        }

        if (count($segments) === 2 && strtolower($segments[1]) === '.search') {
            if ($method !== 'POST') {
                throw new ScimException(
                    Response::HTTP_METHOD_NOT_ALLOWED,
                    'Only POST is allowed on /.search endpoints.',
                    null,
                    ['Allow' => 'POST']
                );
            }
            $body = ScimPatchProcessor::decodeJsonBody((string) $request->getContent());
            $page = $service->listUsers($configEntity, $body, $baseScimUrl);
            return ScimResponseFactory::listResponse($page['resources'], $page['total'], $page['startIndex']);
        }

        if (count($segments) === 2) {
            $userId = $this->parseResourceId($segments[1], 'User');
            return match ($method) {
                'GET'    => ScimResponseFactory::resource($service->getUser($configEntity, $userId, $baseScimUrl)),
                'PUT'    => ScimResponseFactory::resource(
                    $service->replaceUser(
                        $configEntity,
                        $userId,
                        ScimPatchProcessor::decodeJsonBody((string) $request->getContent()),
                        $baseScimUrl
                    )
                ),
                'PATCH'  => ScimResponseFactory::resource(
                    $service->patchUser(
                        $configEntity,
                        $userId,
                        ScimPatchProcessor::decodeJsonBody((string) $request->getContent()),
                        $baseScimUrl
                    )
                ),
                'DELETE' => $this->handleDelete(static fn() => $service->deleteUser($configEntity, $userId)),
                default  => throw new ScimException(
                    Response::HTTP_METHOD_NOT_ALLOWED,
                    'Method not allowed on /Users/{id}.',
                    null,
                    ['Allow' => 'GET, PUT, PATCH, DELETE']
                ),
            };
        }

        throw new ScimException(Response::HTTP_NOT_FOUND, 'SCIM User endpoint not found.');
    }

    /**
     * Dispatch /Groups, /Groups/.search, and /Groups/{id} operations.
     *
     * @param Request            $request      Incoming HTTP request.
     * @param string             $method       Uppercase HTTP verb.
     * @param array<int, string> $segments     URL path segments.
     * @param ConfigEntity       $configEntity Verified IdP configuration.
     * @param string             $baseScimUrl  Canonical SCIM base URL.
     * @return Response
     * @throws ScimException
     */
    private function dispatchGroups(
        Request $request,
        string $method,
        array $segments,
        ConfigEntity $configEntity,
        string $baseScimUrl
    ): Response {
        $service = new ScimGroupService();

        if (count($segments) === 1) {
            if ($method === 'GET') {
                $page = $service->listGroups($configEntity, $request->query->all(), $baseScimUrl);
                return ScimResponseFactory::listResponse($page['resources'], $page['total'], $page['startIndex']);
            }
            if ($method === 'POST') {
                $body = ScimPatchProcessor::decodeJsonBody((string) $request->getContent());
                $created = $service->createGroup($configEntity, $body, $baseScimUrl);
                $loc = (string) ($created['meta']['location'] ?? ($baseScimUrl . '/Groups/' . $created['id']));
                return ScimResponseFactory::resource($created, Response::HTTP_CREATED, $loc);
            }
            throw new ScimException(
                Response::HTTP_METHOD_NOT_ALLOWED,
                'Allowed methods on /Groups are GET and POST.',
                null,
                ['Allow' => 'GET, POST']
            );
        }

        if (count($segments) === 2 && strtolower($segments[1]) === '.search') {
            if ($method !== 'POST') {
                throw new ScimException(
                    Response::HTTP_METHOD_NOT_ALLOWED,
                    'Only POST is allowed on /.search endpoints.',
                    null,
                    ['Allow' => 'POST']
                );
            }
            $body = ScimPatchProcessor::decodeJsonBody((string) $request->getContent());
            $page = $service->listGroups($configEntity, $body, $baseScimUrl);
            return ScimResponseFactory::listResponse($page['resources'], $page['total'], $page['startIndex']);
        }

        if (count($segments) === 2) {
            $groupId = $this->parseResourceId($segments[1], 'Group');
            return match ($method) {
                'GET'    => ScimResponseFactory::resource($service->getGroup($configEntity, $groupId, $baseScimUrl)),
                'PUT'    => ScimResponseFactory::resource(
                    $service->replaceGroup(
                        $configEntity,
                        $groupId,
                        ScimPatchProcessor::decodeJsonBody((string) $request->getContent()),
                        $baseScimUrl
                    )
                ),
                'PATCH'  => ScimResponseFactory::resource(
                    $service->patchGroup(
                        $configEntity,
                        $groupId,
                        ScimPatchProcessor::decodeJsonBody((string) $request->getContent()),
                        $baseScimUrl
                    )
                ),
                'DELETE' => $this->handleDelete(static fn() => $service->deleteGroup($configEntity, $groupId)),
                default  => throw new ScimException(
                    Response::HTTP_METHOD_NOT_ALLOWED,
                    'Method not allowed on /Groups/{id}.',
                    null,
                    ['Allow' => 'GET, PUT, PATCH, DELETE']
                ),
            };
        }

        throw new ScimException(Response::HTTP_NOT_FOUND, 'SCIM Group endpoint not found.');
    }

    /**
     * Require HTTP GET on a discovery endpoint or throw 405 Method Not Allowed.
     *
     * @param string   $method   HTTP verb.
     * @param callable $callback Handler returning Response.
     * @return Response
     * @throws ScimException
     */
    private function requireGet(string $method, callable $callback): Response
    {
        if ($method !== 'GET') {
            throw new ScimException(
                Response::HTTP_METHOD_NOT_ALLOWED,
                'Only HTTP GET is allowed on SCIM discovery endpoints.',
                null,
                ['Allow' => 'GET']
            );
        }

        return $callback();
    }

    /**
     * Execute a DELETE handler and return HTTP 204 No Content.
     *
     * @param callable $callback Void delete action.
     * @return Response
     */
    private function handleDelete(callable $callback): Response
    {
        $callback();
        return ScimResponseFactory::noContent();
    }

    /**
     * Parse and validate a numeric SCIM resource ID segment.
     *
     * @param string $rawSegment Raw URL path segment.
     * @param string $typeName   Resource label ('User' or 'Group').
     * @return int
     * @throws ScimException With HTTP 404 when non-numeric or <= 0.
     */
    private function parseResourceId(string $rawSegment, string $typeName): int
    {
        $decoded = trim(rawurldecode($rawSegment));
        if ($decoded === '' || preg_match('/^\d+$/', $decoded) !== 1 || (int) $decoded <= 0) {
            throw new ScimException(Response::HTTP_NOT_FOUND, sprintf('SCIM %s resource not found.', $typeName));
        }

        return (int) $decoded;
    }

    /**
     * Build the canonical HTTPS SCIM v2 base URL for an IdP.
     *
     * @param Request $request Incoming HTTP request.
     * @param int     $idpId   Target IdP configuration ID.
     * @return string
     */
    private function resolveBaseScimUrl(Request $request, int $idpId): string
    {
        $webDir = function_exists('samlsso_webdir') ? rtrim((string) samlsso_webdir(true), '/') : '/plugins/samlsso';
        return rtrim($request->getSchemeAndHttpHost(), '/')
            . $webDir
            . '/'
            . SamlSsoController::SCIM_ROUTE
            . '/'
            . $idpId;
    }
}
