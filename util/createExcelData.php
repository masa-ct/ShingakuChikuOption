<?php
ini_set('display_errors', 1);
set_include_path(__DIR__ . '/../util/Classes');
include_once('PHPExcel.php');
date_default_timezone_set('Asia/Tokyo');
error_reporting(E_ALL);

/**
 * Class createExcelData
 */
class createExcelData
{

    private $_files = [
        1 => '01hokkai.CSV',
        2 => '02aomori.CSV',
        3 => '03iwate.CSV',
        4 => '04miyagi.CSV',
        5 => '05akita.CSV',
        6 => '06yamaga.CSV',
        7 => '07fukush.CSV',
        8 => '08ibarak.CSV',
        9 => '09tochig.CSV',
        10 => '10gumma.CSV',
        11 => '11saitam.CSV',
        12 => '12chiba.CSV',
        13 => '13tokyo.CSV',
        14 => '14kanaga.CSV',
        15 => '15niigat.CSV',
        16 => '16toyama.CSV',
        17 => '17ishika.CSV',
        18 => '18fukui.CSV',
        19 => '19yamana.CSV',
        20 => '20nagano.CSV',
        21 => '21gifu.CSV',
        22 => '22shizuo.CSV',
        23 => '23aichi.CSV',
        24 => '24mie.CSV',
        25 => '25shiga.CSV',
        26 => '26kyouto.CSV',
        27 => '27osaka.CSV',
        28 => '28hyogo.CSV',
        29 => '29nara.CSV',
        30 => '30wakaya.CSV',
        31 => '31tottor.CSV',
        32 => '32shiman.CSV',
        33 => '33okayam.CSV',
        34 => '34hirosh.CSV',
        35 => '35yamagu.CSV',
        36 => '36tokush.CSV',
        37 => '37kagawa.CSV',
        38 => '38ehime.CSV',
        39 => '39kochi.CSV',
        40 => '40fukuok.CSV',
        41 => '41saga.CSV',
        42 => '42nagasa.CSV',
        43 => '43kumamo.CSV',
        44 => '44oita.CSV',
        45 => '45miyaza.CSV',
        46 => '46kagosh.CSV',
        47 => '47okinaw.CSV',
    ];
    private $_db;
    private $_fuken;
    private $_clc;
    private $_clname;
    private $_settings;
    private $_prefectures;

    /**
     * createExcelData constructor.
     * @param PDO $db
     * @param string $clname // 学校名
     * @param array $settings // 地区の設定
     * @param array $prefectures // 地区設定をしている都道府県
     */
    public function __construct($db, $clc, $clname, $settings, $prefectures)
    {
        // 都道府県名の格納
        $this->_fuken = [];
        $sth = $db->query('select cd,name from common_u.fuken order by cd');
        while ($str = $sth->fetch()) {
            $this->_fuken[$str['cd']] = $str['name'];
        }
        // dbのセット
        $this->setDb($db);
        // clcのセット
        $this->setClc($clc);
        // 学校名のセット
        $this->setClname($clname);
        // 地区設定のセット
        $this->setSettings($settings);
        // 地区設定をしている都道府県のセット
        $this->setPrefectures($prefectures);
    }

    public function createExcelFiles()
    {
        $store_dir = __DIR__ . '/../data/';

        // クライアントごとのファイルを作成する
        printf('[%s] %s作成開始' . PHP_EOL, date('Y-m-d H:i:s'), $this->_clname);

        sort($this->_prefectures);
        $use = [];
        foreach ($this->_prefectures as $prefecture) {
            $use[$prefecture] = $store_dir . $this->_files[$prefecture];
        }

        // テキストファイルの書き出し
        $lines = [];
        $this->add_ken($use, $this->_db, $lines);

        // エクセルの作成
        $filename = $store_dir . sprintf('【地区オプション】%s現在の設定.xlsx', $this->_clname);
        $this->createExcel($filename, $lines);

        printf('[%s] %s作成終了' . PHP_EOL, date('Y-m-d H:i:s'), $this->_clname);
    }

    private function add_ken($files, $db, &$lines)
    {

        // 県コードを指定して高校マスタを読み込む
        $sth = $db->prepare(
            "select replace(yubin, '-', '') as yubin,fullname,jusho from koko where ken=? and jusho<> '' and fumei<> '3' order by yubin"
        );

        $naiyo = [];
        $naiyo_koko = [];

        // 連結スイッチ
        $join = false;

        $max = 0;

        foreach ($files as $kencd => $file) {
            // 読み込みファイル
            $fp = fopen($file, "r");

            while ($str = fgets($fp)) {
                $str = mb_convert_encoding($str, "utf-8", "sjis-win");
                $str = str_replace('"', '', $str);
                $data = explode(",", $str);

                // 郵便番号をキーに
                $key = sprintf("%07d", $data[2]);
                if (!array_key_exists($key, $naiyo)) {
                    $naiyo[$key]['ken'] = array();
                    $naiyo[$key]['jichi'] = array();
                    $naiyo[$key]['chou'] = array();
                }

                // 各配列に値を追加していく
                if (!in_array($data[6], $naiyo[$key]['ken'])) {
                    $naiyo[$key]['ken'][] = $data[6];
                }

                if (!in_array($data[7], $naiyo[$key]['jichi'])) {
                    $naiyo[$key]['jichi'][] = $data[7];
                }

                // 町域名の中に全角の小かっこが入っていたら文字の連結をするスイッチを入れる
                if (strpos($data[8], "（") !== false) {
                    $join = true;
                    $chou = "";
                }

                if ($join === true) {
                    $chou .= $data[8];
                    if (strpos($chou, "）") !== false) {
                        $join = false;
                        $naiyo[$key]['chou'][] = $chou;
                        $chou = "";
                    }
                } else {
                    if (!in_array($data[8], $naiyo[$key]['chou'])) {
                        $naiyo[$key]['chou'][] = $data[8];
                    }
                }

                $max = (count($naiyo[$key]['chou']) > $max) ? count($naiyo[$key]['chou']) : $max;
            }

            fclose($fp);

            // 高校の取得
            $sth->execute(array($kencd));
            while ($str = $sth->fetch()) {
                if (!array_key_exists($str['yubin'], $naiyo)) {
                    $naiyo_koko[$str['yubin']]['fuken'] = $this->_fuken[(int)$kencd];
                    $naiyo_koko[$str['yubin']]['city'] = $str['fullname'];
                    $naiyo_koko[$str['yubin']]['chou'] = $str['jusho'];
                }
            }
        }

        // 郵便番号で地区を設定

        // 通常郵便番号分
        foreach ($naiyo as $key => $str) {
            if (array_key_exists($key, $this->_settings)) {
                $naiyo[$key]['chikucd'] = $this->_settings[$key]['chikucd'];
                $naiyo[$key]['chikuname'] = $this->_settings[$key]['chikuname'];
            }
        }
        // 高等学校分
        foreach ($naiyo_koko as $key => $str) {
            if (array_key_exists($key, $this->_settings)) {
                $naiyo_koko[$key]['chikucd'] = $this->_settings[$key]['chikucd'];
                $naiyo_koko[$key]['chikuname'] = $this->_settings[$key]['chikuname'];
            } else {
                $naiyo_koko[$key]['chikucd'] = '';
                $naiyo_koko[$key]['chikuname'] = '';
            }
        }

        // 書き出し
        // 最初に項目列を書き出す
        $gyou = "郵便番号\t都道府県\t地区コード\t地区\t市区町村";
        // 地名の最大数まで項目名を追加
        for ($i = 1; $i <= $max; $i++) {
            $gyou .= "\t地名" . $i;
        }
        $gyou .= "\n";

        $lines[] = explode("\t", trim($gyou));    // エクセル書き出しのために保存

        // ここから内容の書き出し
        foreach ($naiyo as $key => $str) {
            $ken = implode("・", $str['ken']);
            $jichi = implode("・", $str['jichi']);
            $chikucd = isset($str['chikucd']) ? $str['chikucd'] : '';
            $chikuname = isset($str['chikuname']) ? $str['chikuname'] : '';
            $chou = implode("\t", $str['chou']);
            $gyou = sprintf("%s\t%s\t%s\t%s\t%s\t%s\n", $key, $ken, $chikucd, $chikuname, $jichi, $chou);

            $lines[] = explode("\t", trim($gyou)); // エクセル書き出しのために保存
        }

        // ここから独自郵便番号の高校の書き出し
        foreach ($naiyo_koko as $key => $str) {
            $gyou = sprintf(
                "%s\t%s\t%s\t%s\t%s\t%s\n",
                $key,
                $str['fuken'],
                $str['chikucd'],
                $str['chikuname'],
                $str['city'],
                $str['chou']
            );

            $lines[] = explode("\t", trim($gyou)); // エクセル書き出しのために保存
        }
    }

    /**
     * @param string $clname
     */
    public function setClname($clname)
    {
        $this->_clname = $clname;
    }

    /**
     * @param array $settings
     */
    public function setSettings($settings)
    {
        $this->_settings = $settings;
    }

    /**
     * @param array $prefectures
     */
    public function setPrefectures($prefectures)
    {
        $this->_prefectures = $prefectures;
    }

    /**
     * @param string $clc
     */
    public function setClc($clc)
    {
        $this->_clc = $clc;
    }

    /**
     * @param PDO $db
     */
    public function setDb($db)
    {
        $this->_db = $db;
    }

    /**
     * @param string $file_excel
     * @param array $lines
     * @throws PHPExcel_Exception
     * @throws PHPExcel_Reader_Exception
     */
    private function createExcel($file_excel, $lines)
    {
        $cnt_new = 0;       // 新規追加の郵便番号のカウント

        // 基になるファイルを読み込む
        $objPHPExcel = PHPExcel_IOFactory::load('org/original_data_for_settings.xlsx');

        // シートの選択
        $objPHPExcel->setActiveSheetIndex(0);
        $objSheet = $objPHPExcel->getActiveSheet();

        // 見出し行を固定
        $objSheet->freezePane('A2');

        // データを流し込んでいく
        foreach ($lines as $gyou => $line) {
            foreach ($line as $retsu => $data) {
                if ($retsu == 0) {
                    // A列の郵便番号は、文字列としてデータをセットする
                    $objSheet->setCellValueExplicitByColumnAndRow(
                        $retsu,
                        $gyou + 1,
                        $data,
                        PHPExcel_Cell_DataType::TYPE_STRING
                    );
                } elseif ($retsu == 2) {
                    if ($data) {
                        $objSheet->getCellByColumnAndRow($retsu, $gyou + 1)->setvalue($data);
                    } else {
                        $cnt_new++;     // 新規郵便番号カウンタに追加
                        // 地区コードで値のない場合はA〜D列に色をつける
                        for ($i = 0; $i < 4; $i++) {
                            $objSheet->getStyleByColumnAndRow($i, $gyou + 1)->getFill()->setFillType(
                                PHPExcel_Style_Fill::FILL_SOLID
                            );
                            $objSheet->getStyleByColumnAndRow($i, $gyou + 1)->getFill()->getStartColor()->setRGB('FFFF99');
                        }
                    }
                } else {
                    $objSheet->getCellByColumnAndRow($retsu, $gyou + 1)->setvalue($data);
                }
            }
        }

        // 新規郵便番号がない場合はコメントして、ファイル保存をしない
        if ($cnt_new === 0) {
            echo "今回追加で設定の必要な郵便番号はありませんでした。\n";
        } else {
            $objWriter = PHPExcel_IOFactory::createWriter($objPHPExcel, 'Excel2007');
            $objWriter->save($file_excel);
        }

        unset($objWriter);
        unset($objSheet);
        unset($objPHPExcel);
    }

}
