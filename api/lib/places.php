<?php
declare(strict_types=1);
/** Divisional HQ coordinates (approx. city centre, WGS84). */
const BD_DIVISIONS = [
    'Dhaka'      => ['bn' => 'ঢাকা',      'lat' => 23.8103, 'lon' => 90.4125],
    'Chattogram' => ['bn' => 'চট্টগ্রাম',  'lat' => 22.3569, 'lon' => 91.7832],
    'Rajshahi'   => ['bn' => 'রাজশাহী',   'lat' => 24.3745, 'lon' => 88.6042],
    'Khulna'     => ['bn' => 'খুলনা',     'lat' => 22.8456, 'lon' => 89.5403],
    'Barishal'   => ['bn' => 'বরিশাল',    'lat' => 22.7010, 'lon' => 90.3535],
    'Sylhet'     => ['bn' => 'সিলেট',     'lat' => 24.8949, 'lon' => 91.8687],
    'Rangpur'    => ['bn' => 'রংপুর',     'lat' => 25.7439, 'lon' => 89.2752],
    'Mymensingh' => ['bn' => 'ময়মনসিংহ', 'lat' => 24.7471, 'lon' => 90.4203],
];

function nearest_division(float $lat, float $lon): string {
    $best = 'Dhaka'; $bd = INF;
    foreach (BD_DIVISIONS as $k => $v) { $d = ($v['lat'] - $lat) ** 2 + (($v['lon'] - $lon) * cos(deg2rad($lat))) ** 2; if ($d < $bd) { $bd = $d; $best = $k; } }
    return $best;
}
