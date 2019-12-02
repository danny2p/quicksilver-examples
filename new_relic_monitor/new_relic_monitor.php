<?php
// No need to log this script operation in New Relic's stats.
// PROTIP: you might also want to use this snippet if you have PHP code handling
// very fast things like redirects or the like.
if (extension_loaded('newrelic')) {
  newrelic_ignore_transaction();
}

// Initialize New Relic
$api_key = "NEWRELIC_ADMIN_API_KEY";
$nr = new NewRelic($api_key);

// Check for Live Deployments only.
if ($_POST['wf_type'] == 'deploy' && $_ENV['PANTHEON_ENVIRONMENT'] == 'live') {
  // Create New Relic monitor.
  echo "Creating Synthethics Monitor in New Relic...\n";
  $nr->createMonitor();
} else {
  die("\n\nAborting: Only run on live deployments.");
}
echo "Done!";

/**
 * Basic class for New Relic calls.
 */
class NewRelic {

  private $nr; // New Relic account info.
  private $api_key; // New Relic Admin Key
  public $site_uri; // Pantheon Site URI

  /**
   * Initialize class
   *
   * @param [string] $api_key New Relic Admin API key.
   */
  function __construct($api_key) {
    $this->site_uri = 'https://' . $_ENV['PANTHEON_ENVIRONMENT'] . '-' . $_ENV['PANTHEON_SITE_NAME'] . '.pantheonsite.io';
    $this->api_key = $api_key;

    // Fetch metadata from Pantheon's internal API.
    $req = pantheon_curl('https://api.live.getpantheon.com/sites/self/bindings?type=newrelic', null, 8443);
    $meta = json_decode($req['body'], true);

    // Get the right binding for the current ENV.
    // It should be possible to just fetch the one for the current env.
    $this->nr = false;
    foreach($meta as $data) {
      if ($data['environment'] === PANTHEON_ENVIRONMENT) {
        $this->nr = $data;
        break;
      }
    }
    // Fail fast if we're not going to be able to call New Relic.
    if ($this->nr == false) {
      die("\n\nALERT! No New Relic metadata could be found.\n\n");
    }
  }
  
  /**
   * Get a list of monitors.
   *
   * @return [array] $data
   */
  public function getMonitors() {
    $data = $this->curl('https://synthetics.newrelic.com/synthetics/api/v3/monitors?limit=50');
    return $data;
  }

  /**
   * Get a list of ping locations.
   *
   * @return [array] $data
   */
  public function getLocations() {
    $data = $this->curl('https://synthetics.newrelic.com/synthetics/api/v1/locations');
    return $data;
  }

  /**
   * Validate if monitor for current environment already exists.
   *
   * @param [string] $id
   * @return boolean
   * 
   * @todo Finish validating after getting Pro API key.
   */
  public function validateMonitor($id) {
    $monitors = $this->getMonitors();
    return in_array($id, $monitors['name']);
  }

  /**
   * Create a new ping monitor.
   *
   * @param integer $freq The frequency of pings in seconds.
   * @return void
   */
  public function createMonitor($freq = 60) {

    // List of locations.
    $locations = $this->getLocations();

    $body = [
      'name' => $this->nr['app_name'],
      'type' => 'SIMPLE',
      'frequency' => $freq,
      'uri' => $this->site_uri,
      'locations' => [],
      'status' => 'ENABLED',
    ];

    $req = $this->curl('https://synthetics.newrelic.com/synthetics/api/v3/monitors', [], $body, 'POST');
    print_r($req);
  }

  /**
   * Custom curl function for reusability.
   */
  public function curl($url, $headers = [], $body = [], $method = 'GET') {
    // Initialize Curl.
    $ch = curl_init();

    // Include NR API key
    $headers[] = 'X-Api-Key: ' . $this->api_key;

    // Add POST body if method requires.
    if ($method == 'POST') {
      $payload = json_encode($body);
      $headers[] = 'Content-Type: application/json';
      curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    }
    
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    
    $result = curl_exec($ch);
    curl_close($ch);
  
    // print("JSON: " . json_encode($post,JSON_PRETTY_PRINT)); // Uncomment to debug JSON
    return $result;
  }
}