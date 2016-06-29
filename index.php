<?php
require_once __DIR__ . '/CssOptimizer.php';

const DOWNLOAD_DIR = __DIR__ . "/downloads/";
const UPLOAD_DIR = __DIR__ . "/uploads/";
$d_filename = "";

if (isset($_FILES['fileToUpload'])) {
    $up_filename = tempnam(UPLOAD_DIR, '');
    unlink($up_filename);
    move_uploaded_file($_FILES['fileToUpload']['tmp_name'], $up_filename);
    $content = file_get_contents($up_filename);

    $parser = new CssOptimizer($content);
    try {
        $result = $parser->work();
    } catch(Exception $e) {
        echo "<h1>" . $e->getMessage() . "</h1>";
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

</head>
<body>
<form method="post" enctype="multipart/form-data">
    <h1>Css Optimizer</h1>
    <label for="base_url">Choose File</label>
    <div class="fallback">
        <input name="fileToUpload" type="file" multiple />
    </div>
    <br>
    <button type="submit" name="submit">Upload</button>
</form>
</body>
</html>