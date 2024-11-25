<?php

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use GuzzleHttp\Client;

if (!function_exists('checkIfMultiDimArraySame')) {
    function checkIfMultiDimArraySame(array $array1,array $array2):bool
    {
       return (array_diff_recursive($array1,$array2)==[] && array_diff_recursive($array2,$array1)==[]);
    }
}

if (!function_exists('array_diff_recursive')) {
    function array_diff_recursive($array1, $array2) {
        $result = array();
        foreach ($array1 as $key => $value) {
            if (is_array($value) && isset($array2[$key]) && is_array($array2[$key])) {
                $diff = array_diff_recursive($value, $array2[$key]);
                if (!empty($diff)) {
                    $result[$key] = $diff;
                }
            } else {
                if (!array_key_exists($key, $array2) || $array2[$key] !== $value) {
                    $result[$key] = $value;
                }
            }
        }
        return $result;
    }
}

if (!function_exists('downloadJsonlFile')) {
    function downloadJsonlFile($fileUrl, $fileName, $folder = 'files')
    {
        if (!Storage::disk('public')->exists($folder)) {
            Storage::disk('public')->makeDirectory($folder, 0755, true);
        }
        $filePath = Storage::disk('public')->path("{$folder}/{$fileName}");
        $response = (new Client())->get($fileUrl, ['sink' => $filePath]);
        if ($response->getStatusCode() === 200) {
            return true;
        }
        return false;
    }
}
