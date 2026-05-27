<?php
$phar = new Phar('D:/VietVang/SiroSoft/siro-installer/dist/siro.phar');
$files = [];
foreach (new RecursiveIteratorIterator($phar) as $f) {
    $size = filesize($f->getPathname());
    if ($size > 5000) {
        $name = substr($f->getPathname(), 7);
        $files[$name] = $size;
    }
}
arsort($files);
$total = 0;
$i = 0;
echo "Biggest files in PHAR:\n\n";
foreach ($files as $name => $size) {
    echo str_pad(round($size/1024, 1) . ' KB', 12) . '  ' . $name . "\n";
    $total += $size;
    $i++;
    if ($i >= 25) break;
}
echo "\nTotal visible: " . round($total/1024, 1) . " KB\n";
echo "PHAR total: " . round(filesize($phar->getPath())/1024/1024, 2) . " MB\n";
