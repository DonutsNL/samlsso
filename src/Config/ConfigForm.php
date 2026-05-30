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
 *  @version    1.3.0
 *  @author     Chris Gralike
 *  @copyright  Copyright (c) 2024 by Chris Gralike
 *  @license    GPLv3+
 *  @see        https://github.com/DonutsNL/samlSSO/readme.md
 *  @link       https://github.com/DonutsNL/samlSSO
 *  @since      1.0.0
 * ------------------------------------------------------------------------
 **/

namespace GlpiPlugin\Samlsso\Config;


use Html;
use Search;
use Session;
use GlpiPlugin\Samlsso\Config;
use GlpiPlugin\Samlsso\ClaimMap;
use GlpiPlugin\Samlsso\LoginState;
use Glpi\Application\View\TemplateRenderer;
use OneLogin\Saml2\Constants as Saml2Const;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use GlpiPlugin\Samlsso\Controller\SamlSsoController;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Class Handles the Configuration front/config.form.php Form
 */
class ConfigForm    //NOSONAR complexity by design.
{
    /**
     * Handles the form calls from the ConfigController and loads the
     * config idps listing (first screen before selecting a specific config)
     *
     * @param Request $request  Incoming HTTP request
     * @return Response|RedirectResponse|void
     */
    public function invoke(Request $request)
    {
        $action = (string) $request->get('action', '');

        // Handle bulk export (download) action - no page rendering needed
        if ($action === 'backup_all') {
            return $this->exportAllConfigs();
        }

        // Handle bulk restore (upload) action
        if ($action === 'restore_all' && $request->isMethod('POST')) {
            return $this->restoreAllConfigs($request);
        }

        $this->displayUIHeader();
        $this->renderBackupRestoreCard();
        Search::show(Config::class);
    }


    /**
     * Render the Backup & Restore card above the configuration listing.
     * Uses an inline Bootstrap 5 card with a download link for export
     * and a multipart POST form for restore.
     *
     * @return void
     */
    private function renderBackupRestoreCard(): void
    {
        $exportUrl   = PLUGIN_SAMLSSO_WEBDIR . SamlSsoController::CONFIG_ROUTE . '?action=backup_all';
        $restoreUrl  = PLUGIN_SAMLSSO_WEBDIR . SamlSsoController::CONFIG_ROUTE . '?action=restore_all';
        $csrfToken   = \Session::getNewCSRFToken();

        echo <<<HTML
<div class="container-fluid mb-2 d-flex justify-content-end" id="samlsso-backup-toggle-container">
  <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#samlsso-backup-collapse" aria-expanded="false" aria-controls="samlsso-backup-collapse">
    <i class="fas fa-database me-2"></i>Backup / Restore
  </button>
</div>
<div class="collapse container-fluid mb-3" id="samlsso-backup-collapse">
  <div class="card card-body bg-light border-0 shadow-sm py-3">
    <div class="row align-items-center g-3">
      <div class="col">
        <h6 class="mb-1 fw-bold text-dark">Configuration Backup &amp; Restore</h6>
        <p class="text-muted mb-0" style="font-size: 0.85rem;">Export all IDP configurations to a JSON backup file, or restore from a previously exported backup.</p>
      </div>
      <div class="col-auto">
        <a href="{$exportUrl}" id="samlsso-export-btn" class="btn btn-sm btn-outline-primary">
          <i class="fas fa-download me-2"></i>Download Backup
        </a>
      </div>
      <div class="col-auto">
        <form method="POST" action="{$restoreUrl}" enctype="multipart/form-data" id="samlsso-restore-form" onsubmit="return samlssoConfirmRestore()">
          <input type="hidden" name="_glpi_csrf_token" value="{$csrfToken}">
          <div class="input-group input-group-sm">
            <input type="file" name="restore_file" id="samlsso-restore-file" accept=".json,application/json" class="form-control form-control-sm" required onchange="document.getElementById('samlsso-restore-btn').disabled = false;">
            <button class="btn btn-danger" type="submit" id="samlsso-restore-btn" disabled>
              <i class="fas fa-upload me-2"></i>Restore
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<script>
function samlssoConfirmRestore() {
    return confirm(
        '⚠️  WARNING: This will permanently DELETE all current IDP configurations and restore from the backup file.\\n\\n' +
        'This action cannot be undone. Make sure you have a recent backup before proceeding.\\n\\n' +
        'Continue with restore?'
    );
}
</script>
HTML;
    }


    /**
     * Export all IDP configurations and their claim mappings as a single JSON file download.
     *
     * @return Response
     */
    private function exportAllConfigs(): Response
    {
        global $DB;

        $configs = [];
        $configsTable   = Config::getTable();
        $claimMapTable  = ClaimMap::getTable();
        $exportFields   = [
            ConfigEntity::ID, ConfigEntity::NAME, ConfigEntity::CONF_DOMAIN,
            ConfigEntity::CONF_ICON, ConfigEntity::ENFORCE_SSO, ConfigEntity::PROXIED,
            ConfigEntity::STRICT, ConfigEntity::DEBUG, ConfigEntity::USER_JIT,
            ConfigEntity::SYNC_ON_LOGIN,
            ConfigEntity::SECURITY_WANTMESSAGESSIGNED,
            ConfigEntity::SECURITY_WANTASSERTIONSSIGNED,
            ConfigEntity::SECURITY_WANTASSERTIONSENCRYPTED,
            ConfigEntity::SECURITY_SIGNMETADATA,
            ConfigEntity::SECURITY_WANTNAMEID,
            ConfigEntity::SP_CERTIFICATE, ConfigEntity::SP_KEY, ConfigEntity::SP_NAME_FORMAT,
            ConfigEntity::IDP_ENTITY_ID, ConfigEntity::IDP_SSO_URL, ConfigEntity::IDP_SLO_URL,
            ConfigEntity::IDP_CERTIFICATE, ConfigEntity::AUTHN_CONTEXT, ConfigEntity::AUTHN_COMPARE,
            ConfigEntity::ENCRYPT_NAMEID, ConfigEntity::SIGN_AUTHN, ConfigEntity::SIGN_SLO_REQ,
            ConfigEntity::SIGN_SLO_RES, ConfigEntity::COMPRESS_REQ, ConfigEntity::COMPRESS_RES,
            ConfigEntity::XML_VALIDATION, ConfigEntity::DEST_VALIDATION, ConfigEntity::LOWERCASE_URL,
            ConfigEntity::COMMENT, ConfigEntity::IS_ACTIVE,
        ];

        // Fetch all non-deleted configurations
        $cfgIterator = $DB->request([
            'FROM'  => $configsTable,
            'WHERE' => [ConfigEntity::IS_DELETED => 0],
        ]);

        foreach ($cfgIterator as $row) {
            // Collect only known export fields
            $configFields = [];
            foreach ($exportFields as $field) {
                $configFields[$field] = $row[$field] ?? null;
            }

            // Collect associated claim mappings for this config
            $claimMappings = [];
            $cmIterator = $DB->request([
                'FROM'  => $claimMapTable,
                'WHERE' => ['configs_id' => (int)$row['id']],
            ]);
            foreach ($cmIterator as $cmRow) {
                $claimMappings[] = [
                    'target_type'   => (string)($cmRow['target_type']   ?? 'user_field'),
                    'glpi_field'    => (string)($cmRow['glpi_field']    ?? ''),
                    'saml_claim'    => (string)($cmRow['saml_claim']    ?? ''),
                    'default_value' => (string)($cmRow['default_value'] ?? ''),
                    'is_required'   => (int)($cmRow['is_required']      ?? 0),
                ];
            }

            $configs[] = [
                'config'        => $configFields,
                'claim_maps'    => $claimMappings,
            ];
        }

        $payload = [
            'schema_version'    => '1',
            'plugin_version'    => PLUGIN_SAMLSSO_VERSION,
            'exported_at'       => date('c'),
            'configurations'    => $configs,
        ];

        $json     = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $filename = 'samlsso_backup_' . date('Ymd_His') . '.json';

        // Note: do NOT send Content-Length — GLPI's response stack may gzip or buffer
        // the body after this point, causing a mismatch that silently truncates the download.
        return new Response($json, 200, [
            'Content-Type'        => 'application/json; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control'       => 'no-cache, no-store, must-revalidate',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }


    /**
     * Restore all IDP configurations from an uploaded JSON backup file.
     * This is a clean restore: all existing configurations and claim mappings
     * are removed before the backup data is recreated with original IDs.
     *
     * @param  Request $request Incoming HTTP request containing uploaded file
     * @return RedirectResponse
     */
    private function restoreAllConfigs(Request $request): RedirectResponse
    {
        global $DB;

        // --- 1. Read and parse the uploaded JSON file ---
        $uploadedFile = $request->files->get('restore_file');
        if ($uploadedFile === null || !$uploadedFile->isValid()) {
            Session::addMessageAfterRedirect(__('Restore failed: no valid backup file was uploaded.', PLUGIN_NAME), false, ERROR);
            return new RedirectResponse(PLUGIN_SAMLSSO_WEBDIR . SamlSsoController::CONFIG_ROUTE);
        }

        $json = file_get_contents($uploadedFile->getPathname());
        if ($json === false || trim($json) === '') {
            Session::addMessageAfterRedirect(__('Restore failed: unable to read the uploaded file.', PLUGIN_NAME), false, ERROR);
            return new RedirectResponse(PLUGIN_SAMLSSO_WEBDIR . SamlSsoController::CONFIG_ROUTE);
        }

        // Strip UTF-8 BOM if present (some editors add it and json_decode rejects it)
        if (str_starts_with($json, "\xEF\xBB\xBF")) {
            $json = substr($json, 3);
        }

        // Attempt to decode the JSON as-is
        $data = json_decode($json, true);

        // Healing pass 1: strip literal CR bytes (0x0D).
        // Occurs when PEM certificate values in the DB contain Windows CR+LF line endings that
        // ended up as raw control characters inside JSON string values (invalid per RFC 8259).
        // Stripping CR is safe: the JSON structure is unaffected and PEM certificates are
        // valid with LF-only line endings.
        if (!is_array($data) && json_last_error() !== JSON_ERROR_NONE) {
            $healed = str_replace("\r", '', $json);
            if ($healed !== $json) {
                $data = json_decode($healed, true);
            }
        }

        // Healing pass 2: attempt invalid UTF-8 byte sequence repair and retry
        if (!is_array($data) && json_last_error() !== JSON_ERROR_NONE) {
            $repaired = mb_convert_encoding($json, 'UTF-8', 'UTF-8');
            if ($repaired !== false && $repaired !== $json) {
                $data = json_decode($repaired, true);
            }
        }

        if (!is_array($data) || !isset($data['configurations']) || !is_array($data['configurations']) || count($data['configurations']) === 0) {
            $jsonError = json_last_error() !== JSON_ERROR_NONE
                ? ' JSON error: ' . json_last_error_msg() . ' (code ' . json_last_error() . ')'
                : ' Structure check failed: missing or empty configurations array.';
            Session::addMessageAfterRedirect(
                __('Restore failed: the backup file has an invalid or unrecognized format.', PLUGIN_NAME) . $jsonError,
                false,
                ERROR
            );
            return new RedirectResponse(PLUGIN_SAMLSSO_WEBDIR . SamlSsoController::CONFIG_ROUTE);
        }

        // --- 2. Purge existing data (clean restore) ---
        $configsTable  = Config::getTable();
        $claimMapTable = ClaimMap::getTable();

        $DB->delete($claimMapTable, ['id' => ['>', 0]]);
        $DB->delete($configsTable,  ['id' => ['>', 0]]);

        // --- 3. Restore each configuration with original IDs ---
        $exportFields = [
            ConfigEntity::ID, ConfigEntity::NAME, ConfigEntity::CONF_DOMAIN,
            ConfigEntity::CONF_ICON, ConfigEntity::ENFORCE_SSO, ConfigEntity::PROXIED,
            ConfigEntity::STRICT, ConfigEntity::DEBUG, ConfigEntity::USER_JIT,
            ConfigEntity::SYNC_ON_LOGIN,
            ConfigEntity::SECURITY_WANTMESSAGESSIGNED,
            ConfigEntity::SECURITY_WANTASSERTIONSSIGNED,
            ConfigEntity::SECURITY_WANTASSERTIONSENCRYPTED,
            ConfigEntity::SECURITY_SIGNMETADATA,
            ConfigEntity::SECURITY_WANTNAMEID,
            ConfigEntity::SP_CERTIFICATE, ConfigEntity::SP_KEY, ConfigEntity::SP_NAME_FORMAT,
            ConfigEntity::IDP_ENTITY_ID, ConfigEntity::IDP_SSO_URL, ConfigEntity::IDP_SLO_URL,
            ConfigEntity::IDP_CERTIFICATE, ConfigEntity::AUTHN_CONTEXT, ConfigEntity::AUTHN_COMPARE,
            ConfigEntity::ENCRYPT_NAMEID, ConfigEntity::SIGN_AUTHN, ConfigEntity::SIGN_SLO_REQ,
            ConfigEntity::SIGN_SLO_RES, ConfigEntity::COMPRESS_REQ, ConfigEntity::COMPRESS_RES,
            ConfigEntity::XML_VALIDATION, ConfigEntity::DEST_VALIDATION, ConfigEntity::LOWERCASE_URL,
            ConfigEntity::COMMENT, ConfigEntity::IS_ACTIVE,
        ];

        $restoredCount  = 0;
        $errors         = [];
        $now            = date('Y-m-d H:i:s');

        foreach ($data['configurations'] as $index => $entry) {
            if (!isset($entry['config']) || !is_array($entry['config'])) {
                $errors[] = sprintf(__('Entry %d skipped: missing config block.', PLUGIN_NAME), $index);
                continue;
            }

            $cfgData = $entry['config'];
            $origId  = isset($cfgData[ConfigEntity::ID]) ? (int)$cfgData[ConfigEntity::ID] : 0;

            if ($origId <= 0) {
                $errors[] = sprintf(__('Entry %d skipped: missing or invalid id.', PLUGIN_NAME), $index);
                continue;
            }

            // Build insert row; only use known fields that are present in export
            $insertRow = [ConfigEntity::IS_DELETED => 0, 'date_creation' => $now, 'date_mod' => $now];
            foreach ($exportFields as $field) {
                if ($field === ConfigEntity::ID) {
                    $insertRow['id'] = $origId;
                    continue;
                }
                if (array_key_exists($field, $cfgData)) {
                    $insertRow[$field] = $cfgData[$field];
                }
            }

            // Direct DB insert to preserve original ID
            if (!$DB->insert($configsTable, $insertRow)) {
                $errors[] = sprintf(__('Entry %d (%s) could not be inserted into database.', PLUGIN_NAME), $index, $cfgData[ConfigEntity::NAME] ?? '?');
                continue;
            }

            // --- 4. Restore claim mappings for this config ---
            $claimMaps = isset($entry['claim_maps']) && is_array($entry['claim_maps'])
                ? $entry['claim_maps']
                : [];

            $claimMapEntity = new ClaimMapEntity($origId);
            if (!$claimMapEntity->save($claimMaps)) {
                $errors[] = sprintf(__('Config %s restored but claim mappings had validation errors.', PLUGIN_NAME), $cfgData[ConfigEntity::NAME] ?? (string)$origId);
            }

            $restoredCount++;
        }

        // --- 5. Report results ---
        if ($restoredCount > 0) {
            Session::addMessageAfterRedirect(
                sprintf(__('Restore successful: %d IDP configuration(s) restored.', PLUGIN_NAME), $restoredCount)
            );
        }

        foreach ($errors as $err) {
            Session::addMessageAfterRedirect($err, false, ERROR);
        }

        if ($restoredCount === 0) {
            Session::addMessageAfterRedirect(__('Restore completed but no configurations were imported.', PLUGIN_NAME), false, WARNING);
        }

        return new RedirectResponse(PLUGIN_SAMLSSO_WEBDIR . SamlSsoController::CONFIG_ROUTE);
    }

    /**
     * Handles the form calls from the ConfigFormController and loads the
     * configuration item requested from the listing.
     *
     * @param array     $postData $_POST data from form
     * @return Response|RedirectResponse   String containing HTML form with values or redirect into added form.
     */
    public function invokeForm(Request $request): Response|RedirectResponse     // NOSONAR - CRUDE returns by design.
    {
        if ($request->get('action') === 'forcelogoff') {
            $config = new Config();
            if (!$config->canUpdate()) {
                Session::addMessageAfterRedirect(__('You do not have permission to perform this action.', PLUGIN_NAME), false, ERROR);
                return new RedirectResponse(PLUGIN_SAMLSSO_WEBDIR . SamlSsoController::CONFIGFORM_ROUTE);
            }
            $stateId = (int)$request->get('state_id');
            if ($stateId > 0) {
                $loginState = new LoginState();
                if ($loginState->getFromID($stateId)) {
                    $userId = $loginState->getUserId();
                    if ($userId > 0) {
                        $user = new \User();
                        if ($user->getFromDB($userId)) {
                            $user->update(['id' => $userId, 'is_active' => 0]);
                        }
                    }
                    $loginState->setPhase(LoginState::PHASE_FORCE_LOG);
                    Session::addMessageAfterRedirect(__('User disabled and session forced logoff.', PLUGIN_NAME));
                } else {
                    Session::addMessageAfterRedirect(__('Login state session not found.', PLUGIN_NAME), false, ERROR);
                }
            }
            $idpId = !empty($request->get('id')) ? (int) $request->get('id') : -1;
            return new RedirectResponse(PLUGIN_SAMLSSO_WEBDIR . SamlSsoController::CONFIGFORM_ROUTE . '?id=' . $idpId . '#logging');
        }

        $inputBag = $request->getPayload();                                     // Assign the inputBag
        $id = !empty($request->get('id')) ? (int) $request->get('id') : -1;     // Assign the ID if any
        $options['template'] = ( $request->get('template') &&                   // iF set URI?template={template}
                                 ctype_alpha($request->get('update')) ) ?       // iF template only contains alpha txt
                                 $request->get('update') :                     // THEN set it to requested template
                                 'default';                                         // Else fallback to default.
        $options['search'] = (string)$request->get('search', '');
        $options['page'] = !empty($request->get('page')) ? (int)$request->get('page') : 1;
                                 
        // Add using template
        if( !$inputBag->has('update')     &&
            !$inputBag->has('delete')     ){                                // IF the update is empy load a given template for initial form.

            $this->displayUIHeader();
            return $this->showForm($id, $options);                  // Return the form
    
        // Add new item
        }elseif($inputBag->has('update')  &&                                // IF we received an update
                $id == -1                 ){                                    // AND ID param is empty
            $this->displayUIHeader();
            return $this->addSamlConfig($inputBag->getIterator());  // Call Create handler

        // Update an item
        }elseif($inputBag->has('update')  &&                                    // IF update is set
                $id > 0                   ){                                    // AND $id is higher than 0
            return $this->updateSamlConfig($inputBag->getIterator());

        // Delete an item
        }elseif($inputBag->has('delete')  &&                                    // IF get delete
                $id > 0                   ){                                    // AND $id is higer then 0
           return $this->deleteSamlConfig($inputBag->getIterator());
        }else{
            $this->displayUIHeader();
            return new Response('No valid instructions received');
        }
    }


    /**
     * Populates the GLPI headers (UI)
     * Might cause headers to be send prematurely as Html::header contains echo operations.
     *
     * @param void
     * @return void
     */
    private function displayUIHeader()
    {
        Html::header(Config::getTypeName().' entities',                         // Title for browser tab
                     SamlSsoController::CONFIG_ROUTE,
                     SamlSsoController::CONFIG_PNAME,
                     Config::class);
    }


    /**
     * Add new phpSaml configuration
     *
     * @param array     $postData $_POST data from form
     * @return          Response|RedirectResponse          String containing HTML form with values or redirect into added form.
     */
    public function addSamlConfig(\ArrayIterator $postData): Response|RedirectResponse
    {
        // Populate configEntity using post;
        $configEntity = new ConfigEntity(-1, ['template' => 'post', 'postData' => $postData]);
        
        // Populate and validate claim mappings
        $claimMap = isset($postData['claim_map']) && is_array($postData['claim_map']) ? $postData['claim_map'] : [];
        $claimMapEntity = new ClaimMapEntity(-1);
        $claimMapValid = $claimMapEntity->validate($claimMap);

        // Validate configEntity and claim mappings
        if($configEntity->isValid() && $claimMapValid){
            // Get the normalized database fields
            $fields = $configEntity->getDBFields([ConfigEntity::ID, ConfigEntity::CREATE_DATE, ConfigEntity::MOD_DATE]);
            // Get instance of SamlConfig for db update.
            $config = new Config();
            // Perform database insert using db fields.
            if($id = $config->add($fields)) {
                // Save claim mappings if present in postData
                $claimMapEntity = new ClaimMapEntity((int)$id);
                $claimMapEntity->save($claimMap);
                // Leave succes message for user and redirect
                Session::addMessageAfterRedirect(__('Successfully added new samlSSO configuration.', PLUGIN_NAME));
                return new RedirectResponse(PLUGIN_SAMLSSO_WEBDIR.SamlSsoController::CONFIGFORM_ROUTE.'?id='.$id);
            } else {
                // Leave error message for user and regenerate form with values
                Session::addMessageAfterRedirect(__('Unable to add new samlSSO configuration, please review error logging', PLUGIN_NAME));
                return new Response( $this->generateForm($configEntity) );
            }
        }else{
            // Leave error message for user and regenerate form with values
            Session::addMessageAfterRedirect(__('Configuration invalid, please correct all ⭕ errors first', PLUGIN_NAME));
            return new Response( $this->generateForm($configEntity) );
        }
    }

    /**
     * Update phpSaml configuration
     *
     * @param int   $id of configuration to update
     * @param array $postData $_POST data from form
     * @return      Response|RedirectResponse -
     */
    public function updateSamlConfig(\ArrayIterator $postData): Response|RedirectResponse
    {
        // Populate configEntity using post;
        $configEntity = new ConfigEntity(-1, ['template' => 'post', 'postData' => $postData]);
        
        // Populate and validate claim mappings
        $claimMap = isset($postData['claim_map']) && is_array($postData['claim_map']) ? $postData['claim_map'] : [];
        $claimMapEntity = new ClaimMapEntity((int)$postData['id']);
        $claimMapValid = $claimMapEntity->validate($claimMap);

        // Validate configEntity and claim mappings
        if($configEntity->isValid() && $claimMapValid){
            // Get the normalized database fields
            $fields = $configEntity->getDBFields([ConfigEntity::CREATE_DATE, ConfigEntity::IS_DELETED]);
            // Add the cross site request forgery token to the fields
            $fields['_glpi_csrf_token'] = $postData['_glpi_csrf_token'];
            // Get instance of SamlConfig for db update.
            $config = new Config();
            // Perform database update using fields.
            if($config->canUpdate()       &&
               $config->update($fields) ){
                // Save claim mappings
                $claimMapEntity->save($claimMap);
                // Leave a success message for the user and redirect using ID.
                Session::addMessageAfterRedirect(__('Configuration updated successfully', PLUGIN_NAME));
                return new RedirectResponse(PLUGIN_SAMLSSO_WEBDIR.SamlSsoController::CONFIGFORM_ROUTE.'?id='.$postData['id']);
            } else {
                // Leave a failed message
                Session::addMessageAfterRedirect(__('Configuration update failed, check your update rights or error logging', PLUGIN_NAME));
                return new RedirectResponse(PLUGIN_SAMLSSO_WEBDIR.SamlSsoController::CONFIGFORM_ROUTE.'?id='.$postData['id']);
            }
        }else{
            // Leave an error message and reload the form with provided values and errors
            Session::addMessageAfterRedirect(__('Configuration invalid please correct all ⭕ errors first', PLUGIN_NAME));
            $this->displayUIHeader();
            return new Response($this->generateForm($configEntity));
        }
    }

    /**
     * Add new phpSaml configuration
     *
     * @param array $postData $_POST data from form
     * @return      Response|RedirectResponse
     */
    public function deleteSamlConfig(\ArrayIterator $postData): RedirectResponse
    {
        // Get SamlConfig object for deletion
        $config = new Config();
        // Validate user has the rights to delete then delete
        if($config->canPurge()  &&
           $config->delete((array) $postData)){
            // Leave success message and redirect
            Session::addMessageAfterRedirect(__('Configuration deleted successfully', PLUGIN_NAME));
            return new RedirectResponse(PLUGIN_SAMLSSO_WEBDIR.SamlSsoController::CONFIG_ROUTE);
        } else {
            // Leave fail message and redirect back to config.
            Session::addMessageAfterRedirect(__('Not allowed or error deleting SAML configuration!', PLUGIN_NAME));
            return new RedirectResponse(PLUGIN_SAMLSSO_WEBDIR.SamlSsoController::CONFIG_ROUTE.'?id='.$postData['id']);
        }
    }

    /**
     * Figures out what form to show
     *
     * @param  integer  $id       ID the configuration item to show
     * @param  array    $options  Options
     * @return Response
     */
    public function showForm(int $id, array $options = []): Response|RedirectResponse
    {
        if($id === -1 || $id > 0){
            // Generate form using a template
            return new Response($this->generateForm(new ConfigEntity($id, $options), $options));
        }else{
            // Invalid id used redirect back to origin
            return new RedirectResponse(__('Invalid request, redirecting back', PLUGIN_NAME));
        }
    }

     /**
     * Figure out if there are errors in one of the tabs and displays a
     * warning sign if an error is found
     *
     * @param array $fields     from ConfigEntity->getFields()
     */
    private function getTabWarnings(array $fields): array
    {
        // What fields are in what tab
        $tabFields = ['general_warning'     => [configEntity::NAME,
                                                configEntity::CONF_DOMAIN,
                                                configEntity::CONF_ICON,
                                                configEntity::COMMENT,
                                                configEntity::IS_ACTIVE,
                                                configEntity::DEBUG],
                      'transit_warning'     => [configEntity::COMPRESS_REQ,
                                                configEntity::COMPRESS_RES,
                                                configEntity::PROXIED,
                                                configEntity::XML_VALIDATION,
                                                configEntity::DEST_VALIDATION,
                                                configEntity::LOWERCASE_URL],
                      'provider_warning'    => [configEntity::SP_CERTIFICATE,
                                                configEntity::SP_KEY,
                                                configEntity::SP_NAME_FORMAT],
                      'idp_warning'         => [configEntity::IDP_ENTITY_ID,
                                                configEntity::IDP_SSO_URL,
                                                configEntity::IDP_SLO_URL,
                                                configEntity::IDP_CERTIFICATE,
                                                configEntity::AUTHN_CONTEXT,
                                                configEntity::AUTHN_COMPARE],
                      'security_warning'    => [configEntity::ENFORCE_SSO,
                                                configEntity::STRICT,
                                                configEntity::USER_JIT,
                                                configEntity::SYNC_ON_LOGIN,
                                                configEntity::ENCRYPT_NAMEID,
                                                configEntity::SIGN_AUTHN,
                                                configEntity::SIGN_SLO_REQ,
                                                configEntity::SIGN_SLO_RES,
                                                configEntity::SECURITY_WANTMESSAGESSIGNED,
                                                configEntity::SECURITY_WANTASSERTIONSSIGNED,
                                                configEntity::SECURITY_WANTASSERTIONSENCRYPTED,
                                                configEntity::SECURITY_SIGNMETADATA,
                                                configEntity::SECURITY_WANTNAMEID]];
        // Parse config fields
        // https://github.com/DonutsNL/samlsso/issues/27
        // Make sure all tabs are present for twig.
        $warnings = [];
        foreach($tabFields as $tab => $entityFields){
            foreach($entityFields as $field) {
                if(!empty($fields[$field]['errors'])){
                    $warnings[$tab] = '⚠️';
                }else{
                    $warnings[$tab] = '';
                }
                // Add cert validation warnings
                if(!empty($fields[$field]['validate']['validations']['validTo'])   ||
                   !empty($fields[$field]['validate']['validations']['validFrom']) ){
                    $warnings[$tab] = '⚠️';
                }else{
                    $warnings[$tab] = '';
                }
            }
        }
        // Return warnings if any.
        return $warnings;
    }

    /**
     * Generates the HTML for the config form using the GLPI
     * template renderer.
     *
     * @param ConfigEntity $configEntity    Field values to populate in form
     * @return string ConfigForm            HTML
     * @since                               1.0.0
     * @see https://codeberg.org/QuinQuies/glpisaml/issues/17
     */
    private function generateForm(ConfigEntity $configEntity, array $options = [])
    {
        global $CFG_GLPI;
        $fields = $configEntity->getFields();
        // Get warnings tabs
        $tplVars  = [];
        $tplVars = array_merge($tplVars, $this->getTabWarnings($fields));
       
        // Get AuthN context as array
        $fields[ConfigEntity::AUTHN_CONTEXT][ConfigItem::VALUE] = $configEntity->getRequestedAuthnContextArray();

        // get the logging entries, but only if the object already exists
        // https://codeberg.org/QuinQuies/glpisaml/issues/15#issuecomment-1785284
        $search = (string)($options['search'] ?? '');
        $page = !empty($options['page']) ? (int)$options['page'] : 1;
        $limit = 20;

        $idpId = is_numeric($fields[ConfigEntity::ID]['value']) ? (int)$fields[ConfigEntity::ID]['value'] : -1;
        $claimMapEntity = new ClaimMapEntity($idpId);
        
        $claimErrors = [];
        $claimMapValid = true;
        if (isset($_POST['claim_map']) && is_array($_POST['claim_map'])) {
            $claimMappings = $_POST['claim_map'];
            $claimMapValid = $claimMapEntity->validate($claimMappings);
            $claimErrors = $claimMapEntity->getErrors();
            $claimMappings = ClaimMapEntity::sortMappings($claimMappings);
        } else {
            $claimMappings = $claimMapEntity->getMappings();
        }

        $observedClaims = $claimMapEntity->getObservedClaims();
        $presets = ClaimMapEntity::getPresets();

        $claimWarnings = [];
        $hasClaimWarning = false;
        $unusedRuleFields = [];
        if ($idpId > 0) {
            $unusedRuleFields = $claimMapEntity->getUnusedRuleFields();
            if (!empty($unusedRuleFields)) {
                $hasClaimWarning = true;
            }
            if (!empty($observedClaims)) {
                foreach ($claimMappings as $index => $mapping) {
                    $claim = $mapping['saml_claim'] ?? '';
                    if ($claim !== '' && !in_array($claim, $observedClaims, true)) {
                        $claimWarnings[$index] = __('This claim key has not been observed in any SAML responses yet.', PLUGIN_NAME);
                        $hasClaimWarning = true;
                    }
                }
            }
        }

        if ($idpId > 0) {
            $totalCount = LoginState::getLoggingEntriesCount($idpId, $search);
            $totalPages = (int)max(1, ceil($totalCount / $limit));
            if ($page > $totalPages) {
                $page = $totalPages;
            }
            $logging = LoginState::getLoggingEntries($idpId, $search, $page, $limit);
        } else {
            $logging = [];
            $totalCount = 0;
            $totalPages = 1;
            $page = 1;
        }
        
        // Define static field translations
        $tplVars = array_merge($tplVars, [
            'claim_mappings'            =>  $claimMappings,
            'claim_errors'              =>  $claimErrors,
            'observed_claims'           =>  $observedClaims,
            'mapping_presets'           =>  $presets,
            'allowed_user_fields'       =>  ClaimMapItem::ALLOWED_USER_FIELDS,
            'allowed_rule_fields'       =>  ClaimMapItem::ALLOWED_RULE_FIELDS,
            'claim_warnings'            =>  $claimWarnings,
            'unused_rule_fields'        =>  $unusedRuleFields,
            'claim_tab_warning'         =>  ($hasClaimWarning || !$claimMapValid) ? '⚠️' : '',
            'saml_xml_structure'        =>  $fields[ConfigEntity::SAML_XML_STRUCTURE]['value'] ?? '',
            'plugin'                    =>  PLUGIN_NAME,
            'close_form'                =>  Html::closeForm(false),
            'csrf_token'                =>  \Session::getNewCSRFToken(),
            'glpi_rootdoc'              =>  PLUGIN_SAMLSSO_WEBDIR.SamlSsoController::CONFIGFORM_ROUTE.'?id='.$fields[ConfigEntity::ID][ConfigItem::VALUE],
            'glpi_tpl_macro'            =>  '/components/form/fields_macros.html.twig',
            'inputfields'               =>  $fields,
            'buttonsHiddenWarn'         =>  ($configEntity->getConfigDomain()) ? true : false,
            'loggingfields'             =>  $logging,
            'logging_search'            =>  $search,
            'logging_page'              =>  $page,
            'logging_total_pages'       =>  $totalPages,
            'logging_total_count'       =>  $totalCount,
            'entityID'                  =>  $CFG_GLPI['url_base'].'/',
            'acsUrl'                    =>  PLUGIN_SAMLSSO_WEBDIR.SamlSsoController::ACS_ROUTE.'/'.$fields[ConfigEntity::ID][ConfigItem::VALUE],
            'metaUrl'                   =>  PLUGIN_SAMLSSO_WEBDIR.SamlSsoController::META_ROUTE.'/'.$fields[ConfigEntity::ID][ConfigItem::VALUE],
            'sloUrl'                    =>  PLUGIN_SAMLSSO_WEBDIR.SamlSsoController::SLO_ROUTE.'/'.$fields[ConfigEntity::ID][ConfigItem::VALUE],
            'inputOptionsBool'          =>  [ 1                                 => __('Yes', PLUGIN_NAME),
                                              0                                 => __('No', PLUGIN_NAME)],
            'inputOptionsNameFormat'    =>  [Saml2Const::NAMEID_UNSPECIFIED     => __('Unspecified', PLUGIN_NAME),
                                             Saml2Const::NAMEID_EMAIL_ADDRESS   => __('Email Address', PLUGIN_NAME),
                                             Saml2Const::NAMEID_TRANSIENT       => __('Transient', PLUGIN_NAME),
                                             Saml2Const::NAMEID_PERSISTENT      => __('Persistent', PLUGIN_NAME)],
            'inputOptionsAuthnContext'  =>  ['PasswordProtectedTransport'   => __('PasswordProtectedTransport', PLUGIN_NAME),
                                             'Password'                     => __('Password', PLUGIN_NAME),
                                             'X509'                         => __('X509', PLUGIN_NAME),
                                             'none'                         => __('none', PLUGIN_NAME)],
            'inputOptionsAuthnCompare'  =>  ['exact'                        => __('Exact', PLUGIN_NAME),
                                             'minimum'                      => __('Minimum', PLUGIN_NAME),
                                             'maximum'                      => __('Maximum', PLUGIN_NAME),
                                             'better'                       => __('Better', PLUGIN_NAME)],
        ]);

        // https://codeberg.org/QuinQuies/glpisaml/issues/12
        return TemplateRenderer::getInstance()->render('@samlsso/configForm.html.twig',  $tplVars);
    }
}
