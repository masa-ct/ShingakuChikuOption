<?php

/**
 * 日本郵便から郵便番号データをダウンロード
 * Class getZipData
 */
class getZipData
{

    const _files = [
        1 => '01hokkai.zip',
        2 => '02aomori.zip',
        3 => '03iwate.zip',
        4 => '04miyagi.zip',
        5 => '05akita.zip',
        6 => '06yamaga.zip',
        7 => '07fukush.zip',
        8 => '08ibarak.zip',
        9 => '09tochig.zip',
        10 => '10gumma.zip',
        11 => '11saitam.zip',
        12 => '12chiba.zip',
        13 => '13tokyo.zip',
        14 => '14kanaga.zip',
        15 => '15niigat.zip',
        16 => '16toyama.zip',
        17 => '17ishika.zip',
        18 => '18fukui.zip',
        19 => '19yamana.zip',
        20 => '20nagano.zip',
        21 => '21gifu.zip',
        22 => '22shizuo.zip',
        23 => '23aichi.zip',
        24 => '24mie.zip',
        25 => '25shiga.zip',
        26 => '26kyouto.zip',
        27 => '27osaka.zip',
        28 => '28hyogo.zip',
        29 => '29nara.zip',
        30 => '30wakaya.zip',
        31 => '31tottor.zip',
        32 => '32shiman.zip',
        33 => '33okayam.zip',
        34 => '34hirosh.zip',
        35 => '35yamagu.zip',
        36 => '36tokush.zip',
        37 => '37kagawa.zip',
        38 => '38ehime.zip',
        39 => '39kochi.zip',
        40 => '40fukuok.zip',
        41 => '41saga.zip',
        42 => '42nagasa.zip',
        43 => '43kumamo.zip',
        44 => '44oita.zip',
        45 => '45miyaza.zip',
        46 => '46kagosh.zip',
        47 => '47okinaw.zip',
    ];

    /**
     * 郵便番号データのダウンロード
     * @param array $prefectures
     * @return getZipData
     */
    public static function downloadData($prefectures)
    {
        foreach ($prefectures as $prefecture) {
            $folder_path = __DIR__ . '/../data/';
            $file_path = $folder_path . self::_files[$prefecture];
            $csv_file_path = $folder_path . strtoupper(str_replace("zip", "csv", self::_files[$prefecture]));

            // 現存するファイルを消す
            if (is_file($file_path)) {
                unlink($file_path);
            }

            exec(sprintf("wget -O %s http://www.post.japanpost.jp/zipcode/dl/oogaki/zip/%s", $file_path, self::_files[$prefecture]));

            // 既存のcsvファイルを削除
            if (is_file($csv_file_path)) {
                unlink($csv_file_path);
            }

            // 圧縮ファイルを解凍
            $zip = new ZipArchive();
            if ($zip->open($file_path)) {
                if ($zip->extractTo($folder_path)) {
                    $zip->close();
                }
            }

            unlink($file_path);
            if (!is_file($csv_file_path)) {
                return false;
            }
        }
        return true;
    }

    public static function cleanUpZipData()
    {
        foreach (glob(__DIR__ . "/../data/*.CSV") as $filename) {
            unlink(realpath($filename));
        }
    }
}