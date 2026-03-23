<?php
/**
 * Plugin Name: POTA Amelia Blocker - Form based
 * Description: Form alapú multi-table foglalás automatizálás (service=11) + REST endpointok asztalok blokkolásához Amelia API-n keresztül.
 * Version: 1.0.4
 */

if (!defined('ABSPATH')) { exit; }

/**
 * Debug logolás (wp-content/debug.log). Bekapcsolás:
 * wp-config.php:
 *   define('WP_DEBUG', true);
 *   define('WP_DEBUG_LOG', true);
 *   define('POTA_FB_DEBUG', true);
 */
if (!defined('POTA_FB_DEBUG')) {
  define('POTA_FB_DEBUG', false);
}

/** ===== Konfig ===== */
define('POTA_FB_BLOCKING_SERVICE_ID', 1);
define('POTA_FB_LOCATION_ID', 1);

define('POTA_FB_PROVIDER_ID_START', 12);
define('POTA_FB_PROVIDER_ID_END', 20);

define('POTA_FB_TECH_CUSTOMER_ID', 1);
define('POTA_FB_BLOCK_MAP_OPTION', 'pota_fb_amelia_block_map_v1');

/**
 * Multi-table foglalás (trigger service)
 */
define('POTA_FB_TRIGGER_SERVICE_ID', 11);
define('POTA_FB_MULTI_TABLE_TARGET_SERVICE_ID', 11);

/**
 * Amelia custom field ID: 4 (Asztalok checkbox)
 */
define('POTA_FB_SELECTED_TABLES_FIELD_ID', 4);
define('POTA_FB_SELECTED_TABLES_FIELD_LABEL', 'Asztalok');

define('POTA_FB_MULTI_TABLE_NOTIFY_PARTICIPANTS', 0);

define('POTA_FB_MULTI_TABLE_MAP_OPTION', 'pota_fb_amelia_multi_table_map_v1');
define('POTA_FB_MULTI_TABLE_SEQ_OPTION', 'pota_fb_amelia_multi_table_seq_v1');

/**
 * Jelölők internalNotes-hez:
 * - ORIG: az Amelia által létrehozott (vagy rendszer) appointment (recurring esetén minden occurance ide tartozik)
 * - CLONE: a plugin által létrehozott klónok (ezeket skip-eljük, hogy ne legyen végtelen loop)
 */
define('POTA_FB_MULTI_TABLE_NOTE_PREFIX', 'POTA_FB_MULTI_TABLE');
define('POTA_FB_NOTE_TAG_ORIG', 'ORIG');
define('POTA_FB_NOTE_TAG_CLONE', 'CLONE');

/**
 * wp-config.php:
 * define('POTA_BLOCKER_SECRET', '...');
 * define('POTA_AMELIA_API_KEY', '...');
 */

/** ===== Settings (label -> providerId mapping) ===== */
define('POTA_FB_SETTINGS_OPTION_MAP_JSON', 'pota_fb_table_label_map_json_v1');
define('POTA_FB_TABLE_NUMBER_BASE_PROVIDER_ID', POTA_FB_PROVIDER_ID_START);

/** ===== Admin UI: mapping beállítás ===== */
add_action('admin_menu', function () {
  add_options_page(
    'POTA Amelia Blocker - Form based',
    'POTA Amelia (Form based)',
    'manage_options',
    'pota-amelia-form-based',
    'pota_fb_render_settings_page'
  );
});

add_action('admin_init', function () {
  register_setting('pota_fb_settings', POTA_FB_SETTINGS_OPTION_MAP_JSON, array(
    'type' => 'string',
    'sanitize_callback' => 'pota_fb_sanitize_json_text',
    'default' => '',
  ));
});

function pota_fb_sanitize_json_text($value) {
  if (!is_string($value)) return '';
  return trim($value);
}

function pota_fb_render_settings_page() {
  if (!current_user_can('manage_options')) return;

  $value = (string)get_option(POTA_FB_SETTINGS_OPTION_MAP_JSON, '');
  ?>
  <div class="wrap">
    <h1>POTA Amelia Blocker - Form based</h1>

    <p><strong>Felület:</strong> WP Admin → Beállítások → POTA Amelia (Form based)</p>

    <p>Ide tudod felvinni a <strong>checkbox címke → providerId</strong> megfeleltetést (JSON formátumban).</p>
    <p><strong>Példa JSON:</strong></p>
    <pre style="background:#fff;padding:12px;border:1px solid #ddd;max-width:900px;overflow:auto;">{
  "Normál asztal 1": 12,
  "Normál asztal 2": 13,
  "Normál asztal 3": 14,
  "Normál asztal 4": 15,
  "Normál asztal 5": 16
}</pre>

    <form method="post" action="options.php">
      <?php settings_fields('pota_fb_settings'); ?>
      <table class="form-table" role="presentation">
        <tr>
          <th scope="row"><label for="<?php echo esc_attr(POTA_FB_SETTINGS_OPTION_MAP_JSON); ?>">Mapping JSON</label></th>
          <td>
            <textarea
              id="<?php echo esc_attr(POTA_FB_SETTINGS_OPTION_MAP_JSON); ?>"
              name="<?php echo esc_attr(POTA_FB_SETTINGS_OPTION_MAP_JSON); ?>"
              rows="12"
              style="width: 100%; max-width: 900px; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, 'Liberation Mono', 'Courier New', monospace;"
            ><?php echo esc_textarea($value); ?></textarea>
            <p class="description">Kulcs: checkbox value (pl. "Normál asztal 1"), érték: providerId (pl. 12).</p>
          </td>
        </tr>
      </table>
      <?php submit_button('Mentés'); ?>
    </form>

    <?php if (POTA_FB_DEBUG): ?>
      <hr />
      <p><strong>Debug mód aktív</strong> (POTA_FB_DEBUG=true). Log: <code>wp-content/debug.log</code></p>
    <?php endif; ?>
  </div>
  <?php
}

/** ===== REST route-ok ===== */
add_action('rest_api_init', function () {
  register_rest_route('pota/v1', '/amelia/block', array(
    'methods'  => 'POST',
    'callback' => 'pota_fb_rest_block',
    'permission_callback' => '__return_true',
  ));

  register_rest_route('pota/v1', '/amelia/unblock', array(
    'methods'  => 'POST',
    'callback' => 'pota_fb_rest_unblock',
    'permission_callback' => '__return_true',
  ));
});

/** ===== Amelia hook ===== */
add_action('amelia_after_booking_added', 'pota_fb_on_amelia_booking_added_multi_table', 20, 1);

function pota_fb_on_amelia_booking_added_multi_table($payload) {
  $appointment = pota_fb_locate_appointment_array($payload);
  if (!is_array($appointment)) {
    pota_fb_log('hook payload: no appointment array', array('type' => gettype($payload)));
    return;
  }

  $serviceId = pota_fb_extract_service_id($appointment);
  if ($serviceId !== (int)POTA_FB_TRIGGER_SERVICE_ID) {
    pota_fb_log('skip: serviceId mismatch', array('serviceId' => $serviceId));
    return;
  }

  $appointmentId = (int)pota_fb_arr_get($appointment, array('id'), 0);
  if ($appointmentId <= 0) {
    pota_fb_log('skip: missing appointmentId', array());
    return;
  }

  $internalNotes = (string)pota_fb_arr_get($appointment, array('internalNotes'), '');

  // Recurring fix: only skip clones, not originals/recurring occurrences.
  if (pota_fb_is_clone_notes($internalNotes)) {
    pota_fb_log('skip: clone marker present', array('appointmentId' => $appointmentId));
    return;
  }

  $map = pota_fb_get_multi_table_map();
  if (isset($map[$appointmentId])) {
    pota_fb_log('skip: already processed', array('appointmentId' => $appointmentId));
    return;
  }

  $dtStart = pota_fb_extract_datetime_from_appointment($appointment, 'bookingStart');
  if (!$dtStart) {
    pota_fb_log('skip: missing bookingStart', array('appointmentId' => $appointmentId));
    return;
  }

  $dtEnd = pota_fb_extract_datetime_from_appointment($appointment, 'bookingEnd');
  if (!$dtEnd) {
    $dur = pota_fb_extract_duration_seconds($appointment);
    if ($dur <= 0) {
      pota_fb_log('skip: missing bookingEnd and duration', array('appointmentId' => $appointmentId));
      return;
    }
    $dtEnd = $dtStart->modify('+' . $dur . ' seconds');
  }

  if ($dtEnd <= $dtStart) {
    pota_fb_log('skip: end <= start', array('appointmentId' => $appointmentId));
    return;
  }

  $providerIds = pota_fb_extract_selected_tables_provider_ids($appointment);
  pota_fb_log('selected tables -> providerIds', array('appointmentId' => $appointmentId, 'providerIds' => $providerIds));

  if (empty($providerIds)) {
    pota_fb_log('skip: empty providerIds (check customFields decode / mapping)', array('appointmentId' => $appointmentId));
    return;
  }

  $providerIds = pota_fb_validate_provider_ids($providerIds);
  if (is_wp_error($providerIds)) {
    pota_fb_log('skip: provider validation failed', $providerIds->get_error_data());
    return;
  }

  $externalId = pota_fb_generate_external_id($dtStart, $appointmentId);
  $locationId = (int)pota_fb_arr_get($appointment, array('locationId'), (int)POTA_FB_LOCATION_ID);

  $firstBooking = pota_fb_first_booking($appointment);
  $customerId = (int)pota_fb_arr_get($firstBooking, array('customerId'), 0);
  if ($customerId <= 0) {
    pota_fb_log('skip: missing customerId', array('appointmentId' => $appointmentId));
    return;
  }

  $durationSeconds = $dtEnd->getTimestamp() - $dtStart->getTimestamp();
  $bookingStartStr = $dtStart->format('Y-m-d H:i');

  $customFieldsRaw = pota_fb_arr_get($firstBooking, array('customFields'), '{}');
  $status = (string)pota_fb_arr_get($firstBooking, array('status'), 'approved');
  $persons = (int)pota_fb_arr_get($firstBooking, array('persons'), 1);
  $extras = pota_fb_arr_get($firstBooking, array('extras'), array());
  if (!is_array($extras)) $extras = array();

  // Original appointment -> first selected table
  $canonicalProviderId = (int)$providerIds[0];

  $origNote = pota_fb_format_note(POTA_FB_NOTE_TAG_ORIG, $externalId, null);
  $newNotes = pota_fb_append_note_once($internalNotes, $origNote);

  $updatePayload = array(
    'providerId' => $canonicalProviderId,
    'notifyParticipants' => 0,
    'internalNotes' => $newNotes,
  );

  $u = pota_fb_amelia_request('POST', '/appointments/' . $appointmentId, $updatePayload);
  if (is_wp_error($u)) {
    pota_fb_log('update original appointment failed', $u->get_error_data());
  }

  // clones for remaining tables
  $createdAppointmentIds = array();
  $cloneProviderIds = array_values(array_slice($providerIds, 1));

  foreach ($cloneProviderIds as $pid) {
    $cloneNote = pota_fb_format_note(POTA_FB_NOTE_TAG_CLONE, $externalId, $appointmentId);

    $payload2 = array(
      'serviceId' => (int)POTA_FB_MULTI_TABLE_TARGET_SERVICE_ID,
      'providerId' => (int)$pid,
      'locationId' => (int)$locationId,
      'bookingStart' => $bookingStartStr,
      'notifyParticipants' => (int)POTA_FB_MULTI_TABLE_NOTIFY_PARTICIPANTS,
      'internalNotes' => $cloneNote,
      'bookings' => array(
        array(
          'customerId' => (int)$customerId,
          'status' => $status,
          'duration' => (int)$durationSeconds,
          'persons' => (int)$persons,
          'extras' => $extras,
          'customFields' => is_string($customFieldsRaw) ? $customFieldsRaw : wp_json_encode($customFieldsRaw),
        ),
      ),
      'recurring' => array(),
    );

    $res = pota_fb_amelia_request('POST', '/appointments', $payload2);
    if (is_wp_error($res)) {
      pota_fb_log('clone create failed', array('providerId' => (int)$pid, 'error' => $res->get_error_data()));
      foreach ($createdAppointmentIds as $aid) {
        pota_fb_amelia_request('POST', '/appointments/delete/' . intval($aid), null);
      }
      return;
    }

    $newId = isset($res['data']['appointment']['id']) ? (int)$res['data']['appointment']['id'] : 0;
    if ($newId <= 0) {
      pota_fb_log('clone create: missing new appointment id', array('providerId' => (int)$pid, 'response' => $res));
      foreach ($createdAppointmentIds as $aid) {
        pota_fb_amelia_request('POST', '/appointments/delete/' . intval($aid), null);
      }
      return;
    }

    $createdAppointmentIds[] = $newId;
  }

  $map[$appointmentId] = array(
    'external_id' => $externalId,
    'service_id' => (int)POTA_FB_MULTI_TABLE_TARGET_SERVICE_ID,
    'start' => $bookingStartStr,
    'end' => $dtEnd->format('Y-m-d H:i'),
    'providerIds' => $providerIds,
    'created_appointment_ids' => $createdAppointmentIds,
  );
  pota_fb_set_multi_table_map($map);

  pota_fb_log('multi-table created', array('appointmentId' => $appointmentId, 'created' => $createdAppointmentIds));
}

function pota_fb_format_note($tag, $externalId, $origAppointmentIdOrNull) {
  $s = POTA_FB_MULTI_TABLE_NOTE_PREFIX . ' ' . $tag . ' ' . $externalId;
  if ($origAppointmentIdOrNull !== null) {
    $s .= ' orig=' . intval($origAppointmentIdOrNull);
  }
  return $s;
}

function pota_fb_append_note_once($notes, $line) {
  $notes = (string)$notes;
  $line = trim((string)$line);
  if ($line === '') return $notes;

  if ($notes !== '' && strpos($notes, $line) !== false) return $notes;

  if ($notes === '') return $line;
  return rtrim($notes) . "\n" . $line;
}

function pota_fb_is_clone_notes($notes) {
  $notes = (string)$notes;
  if ($notes === '') return false;
  if (strpos($notes, POTA_FB_MULTI_TABLE_NOTE_PREFIX) === false) return false;

  if (strpos($notes, ' ' . POTA_FB_NOTE_TAG_CLONE . ' ') !== false) return true;
  if (strpos($notes, 'orig=') !== false) return true;

  return false;
}

function pota_fb_locate_appointment_array($payload) {
  if (is_array($payload) && isset($payload['id']) && (isset($payload['serviceId']) || isset($payload['service']))) {
    return $payload;
  }
  if (is_array($payload) && isset($payload['appointment']) && is_array($payload['appointment'])) {
    return $payload['appointment'];
  }
  if (is_array($payload) && isset($payload['data']['appointment']) && is_array($payload['data']['appointment'])) {
    return $payload['data']['appointment'];
  }
  return is_array($payload) ? $payload : null;
}

/** ===== REST: unblock ===== */
function pota_fb_rest_unblock(WP_REST_Request $req) {
  pota_fb_require_secret_or_die();

  $externalId = trim((string)$req->get_param('external_id'));
  if ($externalId === '') {
    return new WP_REST_Response(array('error' => 'external_id kötelező'), 400);
  }

  $map = pota_fb_get_block_map();
  $appointmentIds = isset($map[$externalId]) ? $map[$externalId] : array();

  if (empty($appointmentIds) || !is_array($appointmentIds)) {
    return new WP_REST_Response(array(
      'message' => 'Nincs mit feloldani (nincs mentett blokk erre az external_id-ra)',
      'external_id' => $externalId,
    ), 200);
  }

  $deleted = array();
  $failed  = array();

  foreach ($appointmentIds as $aid) {
    $res = pota_fb_amelia_request('POST', '/appointments/delete/' . intval($aid), null);

    if (is_wp_error($res)) {
      $failed[] = array(
        'appointmentId' => (int)$aid,
        'details' => $res->get_error_data(),
      );
      continue;
    }

    $deleted[] = (int)$aid;
  }

  if (count($failed) === 0) {
    unset($map[$externalId]);
    pota_fb_set_block_map($map);
  }

  return new WP_REST_Response(array(
    'message' => (count($failed) === 0) ? 'Feloldás elkészült' : 'Feloldás részben sikerült',
    'external_id' => $externalId,
    'deleted' => $deleted,
    'failed' => $failed,
  ), (count($failed) === 0) ? 200 : 207);
}

/** ===== REST: block ===== */
function pota_fb_rest_block(WP_REST_Request $req) {
  pota_fb_require_secret_or_die();

  $start = trim((string)$req->get_param('start'));
  $end   = trim((string)$req->get_param('end'));
  $externalId = trim((string)$req->get_param('external_id'));

  if ($externalId === '') return new WP_REST_Response(array('error' => 'external_id kötelező'), 400);
  if ($start === '' || $end === '') return new WP_REST_Response(array('error' => 'start és end kötelező'), 400);

  try {
    $tz = wp_timezone();
    $dtStart = new DateTimeImmutable($start, $tz);
    $dtEnd   = new DateTimeImmutable($end, $tz);
  } catch (Exception $e) {
    return new WP_REST_Response(array(
      'error' => 'Hibás dátum formátum. Várt: YYYY-MM-DD HH:MM',
      'details' => $e->getMessage(),
    ), 400);
  }

  if ($dtEnd <= $dtStart) return new WP_REST_Response(array('error' => 'end legyen később, mint start'), 400);

  $durationSeconds = $dtEnd->getTimestamp() - $dtStart->getTimestamp();
  $bookingStartStr = $dtStart->format('Y-m-d H:i');

  $map = pota_fb_get_block_map();
  if (isset($map[$externalId]) && is_array($map[$externalId]) && count($map[$externalId]) > 0) {
    return new WP_REST_Response(array(
      'message' => 'Már létezik blokk erre az external_id-ra',
      'external_id' => $externalId,
      'appointment_ids' => array_values($map[$externalId]),
    ), 200);
  }

  $providerIds = pota_fb_extract_provider_ids($req);
  if (is_wp_error($providerIds)) {
    return new WP_REST_Response(array(
      'error' => $providerIds->get_error_message(),
      'details' => $providerIds->get_error_data(),
    ), 400);
  }

  $createdAppointmentIds = array();

  foreach ($providerIds as $providerId) {
    $payload = array(
      'serviceId' => (int)POTA_FB_BLOCKING_SERVICE_ID,
      'providerId' => (int)$providerId,
      'locationId' => (int)POTA_FB_LOCATION_ID,
      'bookingStart' => $bookingStartStr,
      'notifyParticipants' => 0,
      'internalNotes' => 'TEREMZAR ' . $externalId,
      'bookings' => array(
        array(
          'customerId' => (int)POTA_FB_TECH_CUSTOMER_ID,
          'status' => 'approved',
          'duration' => (int)$durationSeconds,
          'persons' => 1,
          'extras' => array(),
          'customFields' => '{}',
        ),
      ),
      'recurring' => array(),
    );

    $res = pota_fb_amelia_request('POST', '/appointments', $payload);
    if (is_wp_error($res)) {
      foreach ($createdAppointmentIds as $aid) {
        pota_fb_amelia_request('POST', '/appointments/delete/' . intval($aid), null);
      }
      return new WP_REST_Response(array(
        'error' => 'Amelia API hiba blokkolás közben',
        'providerId' => (int)$providerId,
        'details' => $res->get_error_data(),
      ), 500);
    }

    $appointmentId = isset($res['data']['appointment']['id']) ? (int)$res['data']['appointment']['id'] : 0;
    if ($appointmentId <= 0) {
      foreach ($createdAppointmentIds as $aid) {
        pota_fb_amelia_request('POST', '/appointments/delete/' . intval($aid), null);
      }
      return new WP_REST_Response(array(
        'error' => 'Nem találtam appointment id-t az Amelia válaszban',
        'providerId' => (int)$providerId,
        'response' => $res,
      ), 500);
    }

    $createdAppointmentIds[] = $appointmentId;
  }

  $map[$externalId] = $createdAppointmentIds;
  pota_fb_set_block_map($map);

  return new WP_REST_Response(array(
    'message' => 'Blokkolás elkészült',
    'external_id' => $externalId,
    'appointment_ids' => $createdAppointmentIds,
    'start' => $bookingStartStr,
    'end' => $dtEnd->format('Y-m-d H:i'),
    'providers' => $providerIds,
  ), 200);
}

/** ===== Provider helpers ===== */
function pota_fb_extract_provider_ids(WP_REST_Request $req) {
  $raw = $req->get_param('providerIds');
  if ($raw === null || $raw === '') {
    return new WP_Error('pota_fb_missing_providerIds', 'providerIds kötelező', array());
  }

  $ids = pota_fb_parse_provider_ids($raw);
  if (is_wp_error($ids)) return $ids;
  return pota_fb_validate_provider_ids($ids);
}

function pota_fb_parse_provider_ids($raw) {
  if (is_array($raw)) return pota_fb_normalize_int_list($raw);

  $s = trim((string)$raw);
  if ($s === '') return new WP_Error('pota_fb_empty_providerIds', 'providerIds üres', array());

  if ($s[0] === '[') {
    $json = json_decode($s, true);
    if (!is_array($json)) {
      return new WP_Error('pota_fb_bad_providerIds_json', 'providerIds JSON hibás', array('raw' => $s));
    }
    return pota_fb_normalize_int_list($json);
  }

  $parts = preg_split('/\s*,\s*/', $s);
  return pota_fb_normalize_int_list($parts);
}

function pota_fb_normalize_int_list(array $values) {
  $out = array();
  foreach ($values as $v) {
    if ($v === null || $v === '') continue;
    if (is_string($v)) $v = trim($v);
    if (!is_numeric($v)) {
      return new WP_Error('pota_fb_provider_not_numeric', 'Minden providerId szám kell legyen', array('bad_value' => $v));
    }
    $out[] = (int)$v;
  }

  $out = array_values(array_unique($out));
  sort($out);

  if (count($out) === 0) {
    return new WP_Error('pota_fb_provider_empty', 'Nincs egyetlen providerId sem', array());
  }

  return $out;
}

function pota_fb_validate_provider_ids(array $ids) {
  $ids = array_values(array_unique(array_map('intval', $ids)));
  sort($ids);

  if (count($ids) === 0) return new WP_Error('pota_fb_provider_empty', 'Nincs egyetlen providerId sem', array());

  $min = (int)POTA_FB_PROVIDER_ID_START;
  $max = (int)POTA_FB_PROVIDER_ID_END;

  $bad = array();
  foreach ($ids as $id) {
    if ($id < $min || $id > $max) $bad[] = $id;
  }

  if (count($bad) > 0) {
    return new WP_Error('pota_fb_provider_out_of_range', 'Van providerId az engedélyezett tartományon kívül', array(
      'allowed_min' => $min,
      'allowed_max' => $max,
      'bad' => $bad,
    ));
  }

  return $ids;
}

/** ===== Amelia request + secrets ===== */
function pota_fb_amelia_request($method, $path, $bodyOrNull) {
  if (!defined('POTA_AMELIA_API_KEY') || POTA_AMELIA_API_KEY === '') {
    return new WP_Error('amelia_missing_key', 'Hiányzik a POTA_AMELIA_API_KEY', array());
  }

  $base = home_url('/wp-admin/admin-ajax.php?action=wpamelia_api&call=/api/v1');
  $url  = $base . $path;

  $args = array(
    'method'  => strtoupper($method),
    'timeout' => 25,
    'headers' => array(
      'Amelia' => POTA_AMELIA_API_KEY,
    ),
  );

  if ($bodyOrNull !== null) {
    $args['headers']['Content-Type'] = 'application/json';
    $args['body'] = wp_json_encode($bodyOrNull);
  }

  $resp = wp_remote_request($url, $args);
  if (is_wp_error($resp)) {
    return new WP_Error('amelia_http_error', 'HTTP hiba Amelia felé', array(
      'url' => $url,
      'wp_error' => $resp->get_error_message(),
    ));
  }

  $code = (int) wp_remote_retrieve_response_code($resp);
  $raw  = (string) wp_remote_retrieve_body($resp);
  $json = json_decode($raw, true);

  if ($code < 200 || $code >= 300) {
    return new WP_Error('amelia_api_error', 'Amelia API hiba', array(
      'http_code' => $code,
      'url' => $url,
      'body_raw' => $raw,
      'body_json' => $json,
    ));
  }

  if (!is_array($json)) {
    return array('raw' => $raw, 'http_code' => $code);
  }

  return $json;
}

function pota_fb_require_secret_or_die() {
  $header = isset($_SERVER['HTTP_X_POTA_SECRET']) ? (string)$_SERVER['HTTP_X_POTA_SECRET'] : '';

  if (!defined('POTA_BLOCKER_SECRET') || POTA_BLOCKER_SECRET === '') {
    wp_die('POTA_BLOCKER_SECRET nincs beállítva', 'Forbidden', array('response' => 403));
  }

  if (!hash_equals(POTA_BLOCKER_SECRET, $header)) {
    wp_send_json(array('error' => 'Unauthorized'), 401);
  }
}

/** ===== Storage ===== */
function pota_fb_get_block_map() {
  $map = get_option(POTA_FB_BLOCK_MAP_OPTION, array());
  return is_array($map) ? $map : array();
}

function pota_fb_set_block_map($map) {
  update_option(POTA_FB_BLOCK_MAP_OPTION, $map, false);
}

function pota_fb_get_multi_table_map() {
  $map = get_option(POTA_FB_MULTI_TABLE_MAP_OPTION, array());
  return is_array($map) ? $map : array();
}

function pota_fb_set_multi_table_map($map) {
  update_option(POTA_FB_MULTI_TABLE_MAP_OPTION, $map, false);
}

/** ===== Helpers ===== */
function pota_fb_log($msg, $ctx) {
  if (!POTA_FB_DEBUG || !defined('WP_DEBUG') || !WP_DEBUG) return;
  if (!is_array($ctx)) $ctx = array('ctx' => $ctx);
  error_log('[POTA_FB] ' . $msg . ' ' . wp_json_encode($ctx));
}

function pota_fb_arr_get($arr, array $path, $default = null) {
  $cur = $arr;
  foreach ($path as $k) {
    if (!is_array($cur) || !array_key_exists($k, $cur)) return $default;
    $cur = $cur[$k];
  }
  return $cur;
}

function pota_fb_first_booking(array $appointment) {
  $bookings = pota_fb_arr_get($appointment, array('bookings'), array());
  if (!is_array($bookings) || count($bookings) === 0) return array();
  return is_array($bookings[0]) ? $bookings[0] : array();
}

function pota_fb_extract_service_id(array $appointment) {
  $sid = pota_fb_arr_get($appointment, array('serviceId'), null);
  if ($sid !== null) return (int)$sid;

  $sid = pota_fb_arr_get($appointment, array('service', 'id'), null);
  if ($sid !== null) return (int)$sid;

  return 0;
}

function pota_fb_extract_datetime_from_appointment(array $appointment, $key) {
  $raw = pota_fb_arr_get($appointment, array($key), '');
  if (!is_string($raw) || trim($raw) === '') return null;

  $raw = trim($raw);
  $tz = wp_timezone();

  try {
    return new DateTimeImmutable($raw, $tz);
  } catch (Exception $e) {
    return null;
  }
}

function pota_fb_extract_duration_seconds(array $appointment) {
  $b = pota_fb_first_booking($appointment);
  $dur = (int)pota_fb_arr_get($b, array('duration'), 0);
  return $dur > 0 ? $dur : 0;
}

function pota_fb_generate_external_id(DateTimeImmutable $dtStart, $appointmentId) {
  $seq = (int)get_option(POTA_FB_MULTI_TABLE_SEQ_OPTION, 0) + 1;
  update_option(POTA_FB_MULTI_TABLE_SEQ_OPTION, $seq, false);

  $rand = substr(str_replace('-', '', wp_generate_uuid4()), 0, 8);
  return sprintf('FB-%s-%04d-%s-%d', $dtStart->format('Ymd-Hi'), $seq, $rand, (int)$appointmentId);
}

/** ===== customFields -> providerIds ===== */
function pota_fb_extract_selected_tables_provider_ids(array $appointment) {
  $booking = pota_fb_first_booking($appointment);
  $rawCf = pota_fb_arr_get($booking, array('customFields'), null);
  $cf = pota_fb_decode_custom_fields($rawCf);

  if (!is_array($cf) || count($cf) === 0) {
    pota_fb_log('customFields decode empty', array('raw_type' => gettype($rawCf)));
    return array();
  }

  $entry = null;
  $id = (int)POTA_FB_SELECTED_TABLES_FIELD_ID;

  if ($id > 0) {
    $key = (string)$id;
    if (isset($cf[$key]) && is_array($cf[$key])) $entry = $cf[$key];
  } else {
    $needle = pota_fb_norm_label((string)POTA_FB_SELECTED_TABLES_FIELD_LABEL);
    foreach ($cf as $e) {
      if (!is_array($e)) continue;
      $lbl = (string)pota_fb_arr_get($e, array('label'), '');
      if ($lbl !== '' && pota_fb_norm_label($lbl) === $needle) { $entry = $e; break; }
    }
  }

  if (!is_array($entry)) {
    pota_fb_log('customFields missing entry', array('keys' => array_keys($cf)));
    return array();
  }

  $value = pota_fb_arr_get($entry, array('value'), null);
  return pota_fb_value_to_provider_ids($value);
}

function pota_fb_norm_label($s) {
  $s = (string)$s;
  $s = str_replace(array("\r\n", "\r", "\n", "\t"), ' ', $s);
  $s = trim($s);
  $s = preg_replace('/\s+/u', ' ', $s);
  return $s;
}

function pota_fb_get_table_label_map() {
  $raw = (string)get_option(POTA_FB_SETTINGS_OPTION_MAP_JSON, '');
  $raw = trim($raw);
  if ($raw === '') return array();

  $raw = str_replace(array("\r\n", "\r", "\n", "\t"), ' ', $raw);

  $decoded = json_decode($raw, true);
  if (!is_array($decoded)) {
    pota_fb_log('mapping json invalid', array('json_last_error' => json_last_error()));
    return array();
  }

  $out = array();
  foreach ($decoded as $label => $pid) {
    $label = pota_fb_norm_label($label);
    if ($label === '') continue;
    if (!is_numeric($pid)) continue;
    $out[$label] = (int)$pid;
  }
  return $out;
}

function pota_fb_value_to_provider_ids($value) {
  $labels = array();

  if (is_array($value)) {
    $labels = $value;
  } elseif (is_string($value)) {
    $s2 = str_replace(array("\r\n", "\r", "\n", "\t"), ' ', trim($value));
    if ($s2 === '') return array();

    $j = json_decode($s2, true);
    if (is_array($j)) $labels = $j;
    else $labels = array($s2);
  } else {
    return array();
  }

  $map = pota_fb_get_table_label_map();
  $out = array();

  foreach ($labels as $lbl) {
    if (!is_string($lbl)) continue;
    $key = pota_fb_norm_label($lbl);
    if ($key === '') continue;

    if (array_key_exists($key, $map)) {
      $out[] = (int)$map[$key];
      continue;
    }

    if (preg_match('/(\d+)\s*$/u', $key, $m)) {
      $tableNo = (int)$m[1];
      if ($tableNo > 0) {
        $out[] = (int)POTA_FB_TABLE_NUMBER_BASE_PROVIDER_ID + ($tableNo - 1);
        continue;
      }
    }
  }

  $out = array_values(array_unique(array_map('intval', $out)));
  sort($out);
  return $out;
}

function pota_fb_decode_custom_fields($raw) {
  if (is_array($raw)) return $raw;
  if (!is_string($raw) || trim($raw) === '') return array();

  $s = str_replace(array("\r\n", "\r", "\n", "\t"), ' ', trim($raw));

  $json = json_decode($s, true);
  if (!is_array($json)) {
    pota_fb_log('customFields json_decode failed', array('json_last_error' => json_last_error()));
    return array();
  }
  return $json;
}
