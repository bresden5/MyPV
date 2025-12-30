<?php
declare(strict_types=1);

class MyPV extends IPSModule
{
    public function Create(): void
    {
        parent::Create();

        // Konfigurations-Eigenschaften
        $this->RegisterPropertyString("APIToken", "");
        $this->RegisterPropertyString("SerialNumber", "");
        $this->RegisterPropertyInteger("RefreshInterval", 10000);
        $this->RegisterPropertyInteger("TargetCategory", 0); // Kategorie-ID
        $this->RegisterPropertyBoolean("EnableRefresh", true); // Abruf aktivieren/deaktivieren

        // Timer für automatisches Aktualisieren
        $this->RegisterTimer("RefreshTimer", 10000, 'MP_Refresh($_IPS["TARGET"]);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        // Timer auf den aktuellen Refresh-Wert setzen
        $interval = $this->ReadPropertyInteger("RefreshInterval");
        $this->SetTimerInterval("RefreshTimer", $interval);
    }

    public function Refresh(): void
    {
        // Abruf prüfen
        if (!$this->ReadPropertyBoolean("EnableRefresh")) {
            $this->LogMessage("API-Abruf ist deaktiviert.", KL_MESSAGE);
            return;
        }

        $token = $this->ReadPropertyString("APIToken");
        $serial = $this->ReadPropertyString("SerialNumber");
        $targetCategory = $this->ReadPropertyInteger("TargetCategory");

        if (empty($token) || empty($serial)) {
            $this->LogMessage("API-Token oder Seriennummer fehlt!", KL_WARNING);
            return;
        }

        if ($targetCategory == 0) {
            $targetCategory = $this->InstanceID; // Standard: unter Instanz
        }

        $url = "https://api.my-pv.com/api/v1/device/$serial/data";

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer $token",
            "Accept: application/json"
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            $this->LogMessage("Fehler beim Abrufen von /data: HTTP $httpCode", KL_ERROR);
            return;
        }

        $data = json_decode($response, true);
        if (!$data) {
            $this->LogMessage("Fehler beim Decodieren der JSON-Antwort", KL_ERROR);
            return;
        }

        // Variablen anlegen unter der ausgewählten Kategorie
		foreach ($data as $key => $value) {
			if ($value === null || $value === "" || (is_string($value) && strtolower($value) === "null")) continue;

			if (is_int($value)) $type = VARIABLETYPE_INTEGER;
			elseif (is_float($value)) $type = VARIABLETYPE_FLOAT;
			elseif (is_bool($value)) $type = VARIABLETYPE_BOOLEAN;
			else $type = VARIABLETYPE_STRING;

			list($name, $value) = $this->FormatVariable($key, $value);

			$vid = @IPS_GetVariableIDByName($name, $targetCategory);
			if (!$vid) {
				$vid = IPS_CreateVariable($type);
				IPS_SetParent($vid, $targetCategory);
				IPS_SetName($vid, $name);
			}

			switch ($type) {
				case VARIABLETYPE_BOOLEAN: SetValueBoolean($vid, $value); break;
				case VARIABLETYPE_INTEGER: SetValueInteger($vid, $value); break;
				case VARIABLETYPE_FLOAT: SetValueFloat($vid, $value); break;
				case VARIABLETYPE_STRING: SetValueString($vid, $value); break;
			}
		}
    }

    private function FormatVariable(string $key, $value): array
    {
        $mapping = [
            'power_ac9' => ['Leistung AC9 [W]', 1],
            'power_solar_ac9' => ['Leistung Solar AC9 [W]', 1],
            'power_grid_ac9' => ['Leistung Grid AC9 [W]', 1],
            'volt_mains' => ['Spannung Netz [V]', 1],
            'curr_mains' => ['Strom Netz [A]', 1],
            'freq' => ['Frequenz [Hz]', 0.01],
            'temp1' => ['Temp 1 [°C]', 0.1],
            'temp_ps' => ['Temp PS [°C]', 0.1],
            'boostactive' => ['Boost active', 1],
            'ctrlstate' => ['Control State', 1],
            'error_state' => ['Error State', 1],
            'surplus' => ['Surplus [W]', 1],
            'power_nominal' => ['Nominale Leistung [W]', 1],
            'fwversion' => ['Firmware Version', 1],
            'p9sversion' => ['AC•THOR 9S Version', 1],
            'cur_ip' => ['IP-Adresse', 1],
            'cur_gw' => ['Gateway', 1],
            'cur_sn' => ['Subnet', 1],
            'date' => ['Datum', 1],
            'loctime' => ['Uhrzeit', 1],
        ];

        if (isset($mapping[$key])) {
            $name = $mapping[$key][0];
            $factor = $mapping[$key][1];
            $value = is_numeric($value) ? $value * $factor : $value;
        } else {
            $name = $key;
        }

        return [$name, $value];
    }
}
