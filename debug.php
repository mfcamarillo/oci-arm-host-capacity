<?php
declare(strict_types=1);

require 'vendor/autoload.php';

use Dotenv\Dotenv;
use Hitrov\FileCache;
use Hitrov\OciApi;
use Hitrov\OciConfig;

$envFilename = empty($argv[1]) ? '.env' : $argv[1];
$dotenv = Dotenv::createUnsafeImmutable(__DIR__, $envFilename);
$dotenv->safeLoad();

$config = new OciConfig(
    getenv('OCI_REGION'),
    getenv('OCI_USER_ID'),
    getenv('OCI_TENANCY_ID'),
    getenv('OCI_KEY_FINGERPRINT'),
    getenv('OCI_PRIVATE_KEY_FILENAME'),
    getenv('OCI_AVAILABILITY_DOMAIN') ?: null,
    getenv('OCI_SUBNET_ID'),
    getenv('OCI_IMAGE_ID'),
    (int) getenv('OCI_OCPUS'),
    (int) getenv('OCI_MEMORY_IN_GBS')
);

$api = new OciApi();
$api->setCache(new FileCache($config));

$shape = trim(getenv('OCI_SHAPE'));
$sshKey = trim(getenv('OCI_SSH_PUBLIC_KEY'));

// === 1. MEGLEVO INSTANCE-OK ===
echo "\n========================================\n";
echo "1. MEGLEVO INSTANCE-OK (getInstances)\n";
echo "========================================\n";
try {
    $instances = $api->getInstances($config);
    echo json_encode($instances, JSON_PRETTY_PRINT) . "\n";
} catch (Exception $e) {
    echo "HIBA: " . $e->getMessage() . "\n";
}

// === 2. AVAILABILITY DOMAIN-EK ===
echo "\n========================================\n";
echo "2. AVAILABILITY DOMAIN-EK (getAvailabilityDomains)\n";
echo "========================================\n";
try {
    $availabilityDomains = $api->getAvailabilityDomains($config);
    echo json_encode($availabilityDomains, JSON_PRETTY_PRINT) . "\n";
} catch (Exception $e) {
    echo "HIBA: " . $e->getMessage() . "\n";
    $availabilityDomains = [];
}

// === 3. KULDO JSON BODY ===
echo "\n========================================\n";
echo "3. KULDO JSON BODY (createInstance - NEM KULDI EL!)\n";
echo "========================================\n";

$firstDomain = null;
foreach ($availabilityDomains as $entity) {
    $firstDomain = is_array($entity) ? $entity['name'] : $entity;
    break;
}

if ($firstDomain) {
    $displayName = 'instance-' . date('Ymd-Hi');
    $sourceDetails = $config->getSourceDetails();
    $body = <<<EOD
{
    "metadata": {
        "ssh_authorized_keys": "{$sshKey}"
    },
    "shape": "{$shape}",
    "compartmentId": "{$config->tenancyId}",
    "displayName": "{$displayName}",
    "availabilityDomain": "{$firstDomain}",
    "sourceDetails": {$sourceDetails},
    "createVnicDetails": {
        "assignPublicIp": true,
        "subnetId": "{$config->subnetId}",
        "assignPrivateDnsRecord": true
    },
    "agentConfig": {
        "pluginsConfig": [
            {
                "name": "Compute Instance Monitoring",
                "desiredState": "ENABLED"
            }
        ],
        "isMonitoringDisabled": false,
        "isManagementDisabled": false
    },
    "definedTags": {},
    "freeformTags": {},
    "instanceOptions": {
        "areLegacyImdsEndpointsDisabled": false
    },
    "availabilityConfig": {
        "recoveryAction": "RESTORE_INSTANCE"
    },
    "shapeConfig": {
        "ocpus": {$config->ocpus},
        "memoryInGBs": {$config->memoryInGBs}
    }
}
EOD;
    echo $body . "\n";

    // JSON validacio
    echo "\n--- JSON validacio ---\n";
    $decoded = json_decode($body);
    if (json_last_error() === JSON_ERROR_NONE) {
        echo "JSON: ERVENYES\n";
    } else {
        echo "JSON HIBA: " . json_last_error_msg() . "\n";
        echo "Hiba az SSH kulcsban vagy mas mezőben!\n";
    }
} else {
    echo "Nem sikerult availability domain-t lekerni, a body nem generalhato.\n";
}
