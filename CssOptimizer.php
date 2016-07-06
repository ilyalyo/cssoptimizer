<?php

class CssOptimizer
{
    const TMP_DIR = __DIR__ . "/tmp/";
    const DOWNLOAD_DIR = __DIR__ . "/tmp/download/";

    private $content;

    /**
     * CssOptimizer constructor.
     * @param $content string
     */
    public function __construct($content)
    {
        $this->content = $content;
        self::removeTMPFiles(self::TMP_DIR);
        self::rrmdir(self::DOWNLOAD_DIR);
    }

    /**
     * @return string
     * @throws Exception
     */
    public function work()
    {
        $pages = array_map('trim', explode("\n", $this->content));
        if (count($pages) == 0)
            throw new Exception("empty file exception");
        if (!is_writable(self::TMP_DIR))
            throw new Exception(self::TMP_DIR . " should be writable");
        if (!is_writable(self::DOWNLOAD_DIR))
            throw new Exception(self::DOWNLOAD_DIR . " should be writable");

        $usedCssArray = [];
        $usedJsArray = [];
        $counter = 0;
        foreach ($pages as $page) {
            $page_content = $this->curl($page);
            $page_local_name = self::TMP_DIR . $counter++ . ".html";
            file_put_contents($page_local_name, $page_content);

            $arr_js = $this->getUsedJsLinksArray($page_content);
            foreach ($arr_js as $key => $js)
                if (!array_key_exists($js, $usedJsArray)) {
                    $content = $this->curl($this->getResourceAbsolutePath($page, $js));
                    $arr_js[$key] = self::TMP_DIR . basename($js);
                    file_put_contents($arr_js[$key], $content);
                }
            $usedJsArray = array_unique(array_merge($usedJsArray, $arr_js));
            $usedCss = $this->getUsedCssLinksArray($page_content);
            foreach ($usedCss as $css)
                if (array_key_exists($css, $usedCssArray)) {
                    $usedCssArray[$css]["pages"]["local_names"][] = $page_local_name;
                    $usedCssArray[$css]["pages"]["real_names"][] = $page;
                    $usedCssArray[$css]["js"] = array_unique(array_merge($usedCssArray[$css]["js"], $arr_js));
                } else
                    $usedCssArray[$css] = array(
                        "pages" =>
                            array(
                                "local_names" => array($page_local_name),
                                "real_names" => array($page),
                            ),
                        "js" => $arr_js,
                        "path" => $this->getResourceAbsolutePath($page, $css));
        }

        $trimInfoLen = strlen("################################## PurifyCSS ");
        // https://github.com/purifycss/purifycss
        foreach ($usedCssArray as $css_key => $css_data) {
            $css_content = $this->curl($css_data["path"]);
            $css_tmp_name = self::TMP_DIR . "tmp.css";
            $css_local_name = $this->getValidFileName(basename($css_key));
            file_put_contents($css_tmp_name, $css_content);
            
            $command = "purifycss '" . $css_tmp_name
                . "' '" . implode("' '", $css_data["pages"]["local_names"])
                . "' '" . implode("' '", $css_data["js"])
                . "' --out '" . self::TMP_DIR . $css_local_name
                . "' --info"
                . " --rejected 2>&1 1> /dev/null";
            $output = shell_exec($command);
            $this->addFile($css_data["path"],  self::TMP_DIR .$css_local_name, $css_tmp_name);
            echo "<br><br>";
                        echo "<h2>" . $css_key . "</h2>";
                        echo "This css file was used in: <br>" . implode("<br>", $css_data["pages"]["real_names"]);
                        echo "<br><a href='" . "tmp/" . $css_local_name . "' download>Download optimized</a><br><br>";
                        echo substr($output, $trimInfoLen) . "<br><br>";
        }
        try
        {
            $a = new PharData(self::TMP_DIR. 'download.tar');
            $a->buildFromDirectory(self::DOWNLOAD_DIR);
            $a->compress(Phar::GZ);
        }
        catch (Exception $e)
        {
            echo "Exception : " . $e;
        }
        echo "<a style='position: fixed;top: 40px;right: 40px;' href='/tmp/download.tar' download>Download All</a>";
        die();
    }

    /**
     * @param $css_path
     * @param $css_local_name
     * @param $css_original
     */
    private function addFile($css_path, $css_local_name, $css_original){
        $filename = basename($css_path);
        $path_with_filename = self::DOWNLOAD_DIR . parse_url($css_path, PHP_URL_PATH);
        $path = substr($path_with_filename, 0, strlen($path_with_filename) - strlen($filename));
        if (!file_exists($path))
            mkdir($path, 0777, true);
        copy($css_local_name, $path_with_filename);
        copy($css_original, $path . "ORIGINAL " .$filename);
    }

    private function getValidFileName($filename)
    {
        if (file_exists(self::TMP_DIR . $filename))
            return $this->getValidFileName($filename . "2");
        return $filename;
    }

    private static function removeTMPFiles($path)
    {
        $files = glob($path . "*");
        foreach ($files as $file)
            if (is_file($file))
                unlink($file);
    }
    
    private static function rrmdir($dir) {
        if (is_dir($dir)) {
            $objects = scandir($dir);
            foreach ($objects as $object) {
                if ($object != "." && $object != "..") {
                    if (filetype($dir."/".$object) == "dir")
                        self::rrmdir($dir."/".$object);
                    else unlink   ($dir."/".$object);
                }
            }
            reset($objects);
            if ($dir != self::DOWNLOAD_DIR)
                rmdir($dir);
        }
    }

    /**
     * @param $page_path
     * @param $css_path
     * @return string
     */
    private function getResourceAbsolutePath($page_path, $css_path)
    {
        if (substr($css_path, 0, 3) == "../")
            return $page_path . "/" . $css_path;
        if (substr($css_path, 0, 2) == "//")
            return "http://" . substr($css_path, 2);
        if (substr($css_path, 0, 1) == "/")
            return parse_url($page_path, PHP_URL_SCHEME) . "://" .
            parse_url($page_path, PHP_URL_HOST) . $css_path;
        if (substr($css_path, 0, 4) == "http")
            return $css_path;

        return parse_url($page_path, PHP_URL_SCHEME) . "://" .
        parse_url($page_path, PHP_URL_HOST) . '/' . $css_path;
    }

    /**
     * @param $content
     * @return array
     */
    private function getUsedCssLinksArray($content)
    {
        /* looking for all css href*/
        preg_match_all("/href\s*=\s*\"((?:(?!(\"|')).)*.css)\"/i", $content, $results);
        preg_match_all("/href\s*=\s*'(((?:(?!(\"|')).)*).css)'/i", $content, $results_tmp);

        return array_merge($results[1], $results_tmp[1]);
    }

    /**
     * @param $content
     * @return array
     */
    private function getUsedJsLinksArray($content)
    {
        /* looking for all css href*/
        preg_match_all("/src\s*=\s*\"((?:(?!(\"|')).)*.js)\"/i", $content, $results);
        preg_match_all("/src\s*=\s*'(((?:(?!(\"|')).)*).js)'/i", $content, $results_tmp);

        return array_merge($results[1], $results_tmp[1]);
    }

    /**
     * @param $url
     * @return mixed
     */
    function curl($url)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $data = curl_exec($ch);
        curl_close($ch);

        return $data;
    }

}