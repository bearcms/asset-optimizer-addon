<?php

/*
 * Asset optimizer addon for Bear CMS
 * https://github.com/bearcms/asset-optimizer-addon
 * Copyright (c) Amplilabs Ltd.
 * Free to use under the MIT license.
 */

use BearFramework\App;
use BearCMS\Addons\Addon;

$app = App::get();

$app->bearCMS->addons
    ->register('bearcms/asset-optimizer-addon', function (Addon $addon) use ($app): void {
        $addon->initialize = function () use ($app): void {

            $limit = 1024 * 1024 * 500; // 500MB
            $quality = 'auto';

            $app->assets
                ->addEventListener('prepare', function (\BearFramework\App\Assets\PrepareEventDetails $details) use ($app, $limit, $quality): void {
                    $originalFilename = $details->returnValue;
                    if ($originalFilename === null) {
                        return;
                    }
                    $extension = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));
                    if (array_search($extension, ['png', 'jpg', 'jpeg', 'gif']) !== false) {
                        $tempDir = 'appdata://.temp/asset-optimizer/';
                        $optimizedFilename = $tempDir . md5($originalFilename) . '.' . $extension;
                        if (is_file($optimizedFilename)) {
                            if (filesize($optimizedFilename) === 8) { // special content
                                $optimizedContent = file_get_contents($optimizedFilename);
                                if ($optimizedContent === '00000000') {
                                    return; // show original
                                } elseif ($optimizedContent === date('Ymd')) {
                                    return; // show original and try next day
                                } elseif ($optimizedContent === date('Ym') . '00') {
                                    return; // show original and try next month
                                }
                                // call optimization service
                            } else {
                                $details->returnValue = $optimizedFilename;
                                return;
                            }
                        }
                        if (!is_dir($tempDir)) {
                            mkdir($tempDir, 0777, true);
                        }

                        $monthKey = date('Ym');
                        $usageDataKey = 'bearcms-asset-optimizer/' . $monthKey . '.usage';

                        $logResult = function (string $status, string $info = '') use ($app, $details, $originalFilename, $optimizedFilename, $monthKey, $usageDataKey): void {
                            $data = [
                                'status' => $status,
                                'info' => $info,
                                'filename' => $details->filename,
                                'options' => $details->options,
                                'source' => $originalFilename,
                                'target' => $optimizedFilename
                            ];
                            $app->data->append('bearcms-asset-optimizer/' . $monthKey . '.json.log', json_encode($data) . ',' . "\n");
                            if ($status === 'ok' || $status === 'no-change') {
                                $usage = (int) $app->data->getValue($usageDataKey);
                                $usage += filesize($originalFilename);
                                $app->data->setValue($usageDataKey, (string) $usage);
                            }
                        };

                        $usage = (int) $app->data->getValue($usageDataKey);
                        if ($usage > $limit) {
                            file_put_contents($optimizedFilename, date('Ym') . '00'); // limit reached
                            $logResult('limit-reached');
                        } else {
                            $ch = curl_init();
                            curl_setopt($ch, CURLOPT_URL, "https://asset-optimizer.bearcms.com/");
                            curl_setopt($ch, CURLOPT_POST, 1);
                            curl_setopt($ch, CURLOPT_POSTFIELDS, ['content' => file_get_contents($originalFilename)]);
                            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                                'x-optimizer-secret: ' . \BearCMS\Internal\Config::getHashedAppSecretKey(),
                                'x-optimizer-quality: ' . $quality,
                                'x-optimizer-type: ' . $extension
                            ]);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($ch, CURLOPT_HEADER, 1);
                            curl_setopt($ch, CURLOPT_TIMEOUT, 30);

                            $responseIsOk = false;
                            $response = curl_exec($ch);
                            $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
                            $error = (string)curl_error($ch);
                            curl_close($ch);
                            if ($response !== false) { // not an error
                                $headers = strtolower(substr($response, 0, $headerSize));
                                $body = substr($response, $headerSize);

                                $matches = null;
                                preg_match('/x\-optimizer\-result\:(.*)/', $headers, $matches);
                                $optimizationResult = isset($matches[1]) ? trim($matches[1]) : 'unknown';

                                $matches = null;
                                preg_match('/x\-optimizer\-details\:(.*)/', $headers, $matches);
                                $optimizationDetails = isset($matches[1]) ? trim($matches[1]) : '';

                                if ($optimizationResult === 'ok') {
                                    file_put_contents($optimizedFilename, $body);
                                    $responseIsOk = true;
                                    $logResult('ok', $optimizationDetails);
                                } elseif ($optimizationResult === 'no-change') {
                                    file_put_contents($optimizedFilename, '00000000'); // no change
                                    $responseIsOk = true;
                                    $logResult('no-change', $optimizationDetails);
                                } elseif ($optimizationResult === 'forbidden') {
                                    file_put_contents($optimizedFilename, '00000000'); // forbidden
                                    $logResult('forbidden', $optimizationDetails);
                                }
                            }

                            if ($responseIsOk) {
                                $details->returnValue = $optimizedFilename;
                            } else {
                                file_put_contents($optimizedFilename, date('Ymd')); // date unavailable
                                $logResult('unavailable', $response . ', error: ' . $error);
                            }
                        }
                    }
                });
        };
    });
