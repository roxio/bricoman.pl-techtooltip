<?php
session_start();

// ==========================================
// 1. PROFILES
// ==========================================
class ProfileManager {
    private $profiles_file = 'profiles.json';

    public function __construct() {
        if (!file_exists($this->profiles_file)) {
            file_put_contents($this->profiles_file, json_encode([]));
        }
    }

    public function getProfiles() {
        $content = file_get_contents($this->profiles_file);
        return json_decode($content, true) ?: [];
    }

    public function saveProfile($name, $features) {
        $profiles = $this->getProfiles();
        $profiles[$name] = $features;
        return file_put_contents($this->profiles_file, json_encode($profiles, JSON_PRETTY_PRINT));
    }

    public function deleteProfile($name) {
        $profiles = $this->getProfiles();
        if (isset($profiles[$name])) {
            unset($profiles[$name]);
            return file_put_contents($this->profiles_file, json_encode($profiles, JSON_PRETTY_PRINT));
        }
        return false;
    }
}

// ==========================================
// 2. MAIN
// ==========================================
class BricomanProductScraper {
    private $base_url = "https://www.bricoman.pl";
    private $sitemap_index_url = "https://www.bricoman.pl/pub/media/sitemap/products.xml";
    private $sitemap_cache_dir = 'sitemap_cache';
    private $sitemap_cache_duration = 86400;
    private $pictograms = [];
    
    // Lista cech domyślnie wykluczanych
    private $excluded_features = [
        'Kraj odpowiedzialnego podmiotu gospodarczego produktu w UE',
        'Głębokość transport',
        'Wysokość transport',
        'Szerokość transport',
        'Rodzina kolorów',
        'Kolor rodzina',
        'Kod dostawcy',
        'Referencja dostawcy',
        'Styl płytek',
        'Rektyfikacja [tak/nie]',
        'Grupa wymiarowa',
        'Funkcja antypoślizgowa',
        'Odporność na zużycie',
        'Kolor',
        'Jednostka pojemności ',
        'Gama kolorystyczna'
    ];
    
    private $files_directory = 'generated_files';
    private $max_files = 20;
    
    public function __construct() {
        $directories = [$this->files_directory, $this->sitemap_cache_dir];
        foreach ($directories as $directory) {
            if (!is_dir($directory)) {
                if (!mkdir($directory, 0755, true)) {
                    error_log("Nie można utworzyć folderu: " . $directory);
                }
            }
        }
        $this->cleanupOldFiles();
    }

    // --- METODY SITEMAP I PLIKÓW ---

    public function updateSitemaps() {
        try {
            $index_content = $this->makeRequest($this->sitemap_index_url);
            if (!$index_content) {
                error_log("Nie udało się pobrać głównego sitemap");
                return false;
            }
            preg_match_all('/<loc>(.*?)<\/loc>/', $index_content, $matches);
            $sitemap_urls = $matches[1];
            if (empty($sitemap_urls)) return false;
            
            $updated_count = 0;
            foreach ($sitemap_urls as $sitemap_url) {
                if ($this->shouldUpdateSitemap($sitemap_url)) {
                    if ($this->downloadAndCacheSitemap($sitemap_url)) {
                        $updated_count++;
                    }
                }
            }
            return $updated_count > 0;
        } catch (Exception $e) {
            error_log("Błąd przy aktualizacji sitemap: " . $e->getMessage());
            return false;
        }
    }

    private function shouldUpdateSitemap($sitemap_url) {
        $filename = $this->getSitemapCacheFilename($sitemap_url);
        $cache_file = $this->sitemap_cache_dir . '/' . $filename;
        if (!file_exists($cache_file)) return true;
        $file_age = time() - filemtime($cache_file);
        return $file_age > $this->sitemap_cache_duration;
    }

    private function downloadAndCacheSitemap($sitemap_url) {
        try {
            $content = $this->makeRequest($sitemap_url);
            if (!$content) return false;
            $filename = $this->getSitemapCacheFilename($sitemap_url);
            $cache_file = $this->sitemap_cache_dir . '/' . $filename;
            if (file_put_contents($cache_file, $content)) return true;
            return false;
        } catch (Exception $e) {
            return false;
        }
    }

    private function getSitemapCacheFilename($sitemap_url) {
        return 'sitemap_' . md5($sitemap_url) . '.xml';
    }

    private function getCachedSitemapUrls() {
        $sitemap_files = glob($this->sitemap_cache_dir . '/sitemap_*.xml');
        return $sitemap_files ?: [];
    }

    public function ensureSitemapsUpdated() {
        $cached_sitemaps = $this->getCachedSitemapUrls();
        if (empty($cached_sitemaps)) return $this->updateSitemaps();
        $needs_update = false;
        foreach ($cached_sitemaps as $sitemap_file) {
            if ((time() - filemtime($sitemap_file)) > $this->sitemap_cache_duration) {
                $needs_update = true;
                break;
            }
        }
        if ($needs_update) return $this->updateSitemaps();
        return true;
    }

    public function getFilesDirectory() {
        return $this->files_directory;
    }

    private function cleanupOldFiles() {
        try {
            $files = glob($this->files_directory . '/*.html');
            if ($files && count($files) > $this->max_files) {
                usort($files, function($a, $b) { return filemtime($a) - filemtime($b); });
                $files_to_delete = count($files) - $this->max_files;
                for ($i = 0; $i < $files_to_delete; $i++) {
                    if (file_exists($files[$i])) unlink($files[$i]);
                }
            }
        } catch (Exception $e) {}
    }

    public function getRecentFiles($limit = 10) {
        try {
            $files = glob($this->files_directory . '/*.html');
            if (empty($files)) return [];
            usort($files, function($a, $b) { return filemtime($b) - filemtime($a); });
            $recent_files = [];
            foreach (array_slice($files, 0, $limit) as $file) {
                if (file_exists($file)) {
                    $recent_files[] = [
                        'filename' => basename($file),
                        'path' => $file,
                        'size' => $this->formatFileSize(filesize($file)),
                        'date' => date('d.m.Y H:i', filemtime($file)),
                        'url' => $this->files_directory . '/' . basename($file)
                    ];
                }
            }
            return $recent_files;
        } catch (Exception $e) { return []; }
    }

    private function formatFileSize($bytes) {
        if ($bytes == 0) return '0 B';
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = floor(log($bytes, 1024));
        return round($bytes / pow(1024, $i), 2) . ' ' . $units[$i];
    }

    public function generateFilename($reference_numbers) {
        $refs = array_slice($reference_numbers, 0, 3);
        $filename_refs = implode('_', $refs);
        if (count($reference_numbers) > 3) $filename_refs .= '_and_' . (count($reference_numbers) - 3) . '_more';
        $filename_refs = preg_replace('/[^a-zA-Z0-9_-]/', '_', $filename_refs);
        $filename_refs = substr($filename_refs, 0, 100);
        return "ref_{$filename_refs}_.html";
    }

    // --- METODY WYSZUKIWANIA I POBIERANIA ---

    public function findProductByReference($reference_number) {
        $this->ensureSitemapsUpdated();
        $cached_sitemaps = $this->getCachedSitemapUrls();
        foreach ($cached_sitemaps as $sitemap_file) {
            try {
                $product_url = $this->searchInCachedSitemap($sitemap_file, $reference_number);
                if ($product_url) return $product_url;
            } catch (Exception $e) {}
        }
        return $this->findProductOnline($reference_number);
    }

    private function findProductOnline($reference_number) {
        try {
            $index_content = $this->makeRequest($this->sitemap_index_url);
            if (!$index_content) return null;
            preg_match_all('/<loc>(.*?)<\/loc>/', $index_content, $matches);
            $sitemap_urls = $matches[1];
            foreach ($sitemap_urls as $sitemap_url) {
                $product_url = $this->searchInSitemap($sitemap_url, $reference_number);
                if ($product_url) {
                    $this->downloadAndCacheSitemap($sitemap_url);
                    return $product_url;
                }
            }
            return null;
        } catch (Exception $e) { return null; }
    }

    private function searchInSitemapContent($xml_content, $reference_number) {
        if (!$xml_content) return null;
        $pattern = '/<loc>(.*?' . preg_quote($reference_number, '/') . '.*?)<\/loc>/';
        if (preg_match_all($pattern, $xml_content, $matches)) {
            foreach ($matches[1] as $url) {
                if (strpos($url, $reference_number) !== false) return trim($url);
            }
        }
        return null;
    }

    private function searchInCachedSitemap($sitemap_file, $reference_number) {
        $xml_content = file_get_contents($sitemap_file);
        return $this->searchInSitemapContent($xml_content, $reference_number);
    }

    private function searchInSitemap($sitemap_url, $reference_number) {
        $xml_content = $this->makeRequest($sitemap_url);
        return $this->searchInSitemapContent($xml_content, $reference_number);
    }

    public function getProductData($product_url, $reference_number) {
        try {
            $html = $this->makeRequest($product_url);
            if (!$html) return ["error" => "Nie udało się pobrać strony produktu"];
            return $this->parseProductPage($html, $product_url, $reference_number);
        } catch (Exception $e) {
            return ["error" => "Błąd przy pobieraniu danych: " . $e->getMessage()];
        }
    }

    private function makeRequest($url) {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36\r\n" .
                           "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8\r\n",
                'timeout' => 15, 'ignore_errors' => true
            ],
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]
        ]);
        $response = @file_get_contents($url, false, $context);
        if ($response === false) throw new Exception("HTTP request failed for URL: " . $url);
        return $response;
    }

    // --- PARSOWANIE ---

    private function parseProductPage($html, $product_url, $reference_number) {
        $data = [
            'title' => ['Produkt Bricoman'],
            'main_sku' => [$reference_number],
            'product_picture' => null,
            'product_brand' => null,
            'attributes_list_object' => $this->extractTechnicalSpecifications($html),
            'pictograms' => [],
            'print_date' => date('d.m.Y'),
            'print_hour' => date('H:i')
        ];
        
        if (preg_match('/<h1[^>]*>(.*?)<\/h1>/i', $html, $match)) {
            $data['title'][0] = strip_tags(trim($match[1]));
        }
        
        $data['product_picture'] = $this->extractProductPicture($html, $reference_number);
        if (!$data['product_picture']) {
            $image_patterns = [
                '/<img[^>]*class="[^"]*b-product-carousel__main-slide swiper-slide[^"]*"[^>]*src="([^"]*\.(jpg|jpeg|png|gif))"[^>]*>/i',
                '/<img[^>]*class="[^"]*b-product-carousel__main-slide swiper-slide[^"]*"[^>]*data-src="([^"]*\.(jpg|jpeg|png|gif))"[^>]*>/i',
                '/<div[^>]*class="[^"]*b-product-carousel__main-slide-image[^"]*"[^>]*>.*?<img[^>]*src="([^"]*\.(jpg|jpeg|png|gif))"[^>]*>/is'
            ];
            foreach ($image_patterns as $pattern) {
                if (preg_match($pattern, $html, $match)) {
                    $data['product_picture'] = $this->normalizeUrl($match[1]);
                    break;
                }
            }
        }
        
        $brand_patterns = [
            '/<img[^>]*class="[^"]*b-product-carousel__main-brand-image[^"]*"[^>]*src="([^"]*)"[^>]*>/i',
            '/<img[^>]*class="[^"]*b-product-carousel__main-brand-image[^"]*"[^>]*data-src="([^"]*)"[^>]*>/i',
            '/<div[^>]*class="[^"]*b-product-carousel__main-brand[^"]*"[^>]*>.*?<img[^>]*src="([^"]*)"[^>]*>/is'
        ];
        foreach ($brand_patterns as $pattern) {
            if (preg_match($pattern, $html, $match)) {
                $data['product_brand'] = $this->normalizeUrl($match[1]);
                break;
            }
        }
        
        $this->extractPictogramsFromAccordion($html);
        $data['pictograms'] = $this->pictograms;
        return $data;
    }

    private function extractProductPicture($html, $reference_number) {
        $pattern = '/<img[^>]*(?:src|data-src)="([^"]*' . preg_quote($reference_number, '/') . '[^"]*_picture\.jpeg[^"]*)"[^>]*>/i';
        if (preg_match($pattern, $html, $match)) {
            return $this->normalizeUrl($this->cleanImageUrl($match[1]));
        }
        $patterns = [
            '/<img[^>]*data-src="([^"]*' . preg_quote($reference_number, '/') . '[^"]*_picture\.jpeg[^"]*)"[^>]*>/i',
            '/<img[^>]*src="([^"]*' . preg_quote($reference_number, '/') . '[^"]*_picture\.jpeg[^"]*)"[^>]*>/i',
            '/<div[^>]*data-image="([^"]*' . preg_quote($reference_number, '/') . '[^"]*_picture\.jpeg[^"]*)"[^>]*>/i',
            '/<img[^>]*data-src="([^"]*' . preg_quote($reference_number, '/') . '[^"]*_picture_01\.jpeg[^"]*)"[^>]*>/i',
            '/<img[^>]*src="([^"]*' . preg_quote($reference_number, '/') . '[^"]*_picture_01\.jpeg[^"]*)"[^>]*>/i',
            '/<div[^>]*data-image="([^"]*' . preg_quote($reference_number, '/') . '[^"]*_picture_01\.jpeg[^"]*)"[^>]*>/i'
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $html, $match)) {
                $url = $this->cleanImageUrl($match[1]);
                $image_url = $this->normalizeUrl($url);
                if ($this->checkImageExists($image_url)) return $image_url;
            }
        }
        return null;
    }

    private function cleanImageUrl($url) {
        if (strpos($url, '.jpeg?') !== false) $url = substr($url, 0, strpos($url, '.jpeg?') + 5);
        if (strpos($url, '.jpg?') !== false) $url = substr($url, 0, strpos($url, '.jpg?') + 4);
        return $url;
    }

    private function checkImageExists($url) {
        $headers = @get_headers($url);
        return ($headers && strpos($headers[0], '200') !== false);
    }

    private function normalizeUrl($url) {
        if (!$url) return null;
        if (strpos($url, 'http') === 0) return $url;
        if (strpos($url, '//') === 0) return 'https:' . $url;
        return $this->base_url . $url;
    }

    private function extractPictogramsFromAccordion($html) {
        $pictograms = [];
        if (preg_match('/<m-accordion[^>]*class="[^"]*b-product-details__accordion[^"]*"[^>]*>(.*?)<\/m-accordion>/is', $html, $accordion_match)) {
            $accordion_section = $accordion_match[1];
            if (preg_match_all('/<img[^>]*(?:src|data-src)="([^"]*(_picto)?\.(jpg|jpeg|png|svg)(\?[^"]*)?)"[^>]*>/i', $accordion_section, $img_matches)) {
                foreach ($img_matches[1] as $img_url) {
                    $normalized = $this->normalizeUrl($img_url);
                    if ($normalized) $pictograms[] = $normalized;
                }
            }
        }
        if (preg_match_all('/<img[^>]*(?:src|data-src)="([^"]*?_picto\.(jpg|jpeg|png|svg)(\?[^"]*)?)"[^>]*>/i', $html, $all_matches)) {
            foreach ($all_matches[1] as $img_url) {
                $normalized = $this->normalizeUrl($img_url);
                if ($normalized) $pictograms[] = $normalized;
            }
        }
        $feature_pictograms = $this->extractPictogramsFromFeatures($html);
        $pictograms = array_merge($pictograms, $feature_pictograms);
        $this->pictograms = array_unique($pictograms);
    }

    private function extractPictogramsFromFeatures($html) {
        $pictograms = [];
        if (preg_match('/<h3[^>]*>[^<]*Cechy produktu[^<]*<\/h3>(.*?)<(h3|div|section)/is', $html, $section_match)) {
            $specs_section = $section_match[1];
            if (preg_match_all('/<img[^>]*src="([^"]*(_picto)?\.(svg|png|jpg|jpeg))"[^>]*>/i', $specs_section, $img_matches)) {
                foreach ($img_matches[1] as $img_url) {
                    $pictograms[] = $this->normalizeUrl($img_url);
                }
            }
        }
        return $pictograms;
    }

    private function extractTechnicalSpecifications($html) {
        $specs = [];
        if (preg_match('/<h3[^>]*>[^<]*Cechy produktu[^<]*<\/h3>(.*?)<(h3|div|section)/is', $html, $section_match)) {
            $specs_section = $section_match[1];
            if (preg_match_all('/<li[^>]*>(.*?)<\/li>/is', $specs_section, $li_matches)) {
                foreach ($li_matches[1] as $li) {
                    $spec = $this->parseFeatureItem($li);
                    // Uwaga: Tutaj nie wykluczamy jeszcze cech - pobieramy wszystko.
                    // Filtrowanie nastąpi w kroku 2 przy generowaniu HTML.
                    if ($spec) $specs[] = $spec;
                }
            }
        }
        if (empty($specs)) {
            $section_patterns = [
                '/<div[^>]*class="[^"]*product-specifications[^"]*"[^>]*>(.*?)<\/div>/is',
                '/<table[^>]*class="[^"]*data-table[^"]*"[^>]*>(.*?)<\/table>/is',
                '/<div[^>]*class="[^"]*specification[^"]*"[^>]*>(.*?)<\/div>/is'
            ];
            foreach ($section_patterns as $pattern) {
                if (preg_match($pattern, $html, $match)) {
                    $specs_section = $match[1];
                    if (preg_match_all('/<tr[^>]*>(.*?)<\/tr>/is', $specs_section, $row_matches)) {
                        foreach ($row_matches[1] as $row) {
                            $spec = $this->parseTableRow($row);
                            if ($spec) $specs[] = $spec;
                        }
                    } elseif (preg_match_all('/<div[^>]*class="[^"]*specification-item[^"]*"[^>]*>(.*?)<\/div>/is', $specs_section, $item_matches)) {
                        foreach ($item_matches[1] as $item) {
                            $spec = $this->parseSpecificationItem($item);
                            if ($spec) $specs[] = $spec;
                        }
                    }
                }
            }
        }
        return $specs;
    }

    private function parseFeatureItem($li) {
        $text = strip_tags($li, '<img>');
        $text = preg_replace('/<img[^>]*>/', '', $text);
        $text = trim($text);
        if (!empty($text)) {
            if (strpos($text, ':') !== false) {
                list($label, $value) = explode(':', $text, 2);
                return ['label' => htmlspecialchars(trim($label)), 'value' => htmlspecialchars(trim($value))];
            } else {
                return ['label' => 'Cecha', 'value' => htmlspecialchars($text)];
            }
        }
        return null;
    }

    private function parseTableRow($row) {
        if (preg_match_all('/<t(d|h)[^>]*>(.*?)<\/t(d|h)>/is', $row, $cell_matches)) {
            $cells = $cell_matches[2];
            if (count($cells) >= 2) {
                $label = trim(strip_tags($cells[0]));
                $value = trim(strip_tags($cells[1]));
                $label = preg_replace('/\s+/', ' ', $label);
                $value = preg_replace('/\s+/', ' ', $value);
                if (!empty($label) && !empty($value) && $label !== $value) {
                    return ['label' => htmlspecialchars($label), 'value' => htmlspecialchars($value)];
                }
            }
        }
        return null;
    }

    private function parseSpecificationItem($item) {
        if (preg_match('/<span[^>]*class="[^"]*spec-name[^"]*"[^>]*>(.*?)<\/span>/is', $item, $label_match) &&
            preg_match('/<span[^>]*class="[^"]*spec-value[^"]*"[^>]*>(.*?)<\/span>/is', $item, $value_match)) {
            $label = trim(strip_tags($label_match[1]));
            $value = trim(strip_tags($value_match[1]));
            if (!empty($label) && !empty($value)) {
                return ['label' => htmlspecialchars($label), 'value' => htmlspecialchars($value)];
            }
        }
        return null;
    }

    private function generateBarcode($code) {
        return "https://barcode.tec-it.com/barcode.ashx?data=" . urlencode($code) . "&code=Code128&dpi=120&format=png&unit=px&height=35&width=200&hidehrt=TRUE";
    }

    public function getExcludedFeatures() {
        return $this->excluded_features;
    }

    // --- WYBÓR CECH ---
    
    public function generateMultiProductHtmlTemplate($products_data, $allowed_features = null) {
        $html = '<!DOCTYPE html>
<html>
<head>
    <meta name="generator" content="pdf2htmlEX"/>
    <meta id="format_conf" name="format" content="A4"/>
    <meta id="orientation" name="format" content="L"/>
    <meta http-equiv="X-UA-Compatible" content="IE=edge,chrome=1"/>
    <style>
        @page { size: A4 landscape; margin: 0; }
        body { width: 297mm; height: 210mm; margin: 0; padding: 5mm; font-family: Arial, sans-serif; font-size: 10pt; position: relative; box-sizing: border-box; }
        .page { width: 100%; height: 100%; display: flex; flex-wrap: wrap; gap: 5mm; }
        .product-card { width: calc(50% - 2.5mm); height: calc(100% - 2mm); border: 1px solid #ddd; padding: 3mm; box-sizing: border-box; position: relative; page-break-inside: avoid; }
        .top-border { background-color: #da7625; height: 2mm; margin-bottom: 2mm; }
        .midle-border { background-color: #da7625; height: 1mm; margin: 2mm 0; }
        .table_product_data { margin-top: 2mm; border-collapse: collapse; width: 100%; font-size: 8pt; }
        .table_product_data td { border: 0.7px solid #6c6c6c; padding: 1mm; }
        .title_data { width: 40%; font-weight: bold; font-size: 11pt; background-color: #f2f2f2; }
        .value_data { width: 60%; font-size: 11pt; }
        .brand-picture { max-height: 12mm; max-width: 20mm; object-fit: contain; }
        .barcode { height: 6mm; margin-left: 2mm; vertical-align: middle; }
        .pictograms-container { display: flex; flex-wrap: wrap; gap: 1mm; margin-top: 1mm; padding: 1mm; background: #f9f9f9; border-radius: 1mm; border: 0.3mm solid #da7625; max-height: 22mm; overflow-y: auto; }
        .pictogram { width: 21mm; height: 21mm; object-fit: contain; padding: 0.5mm; background: white; }
        .print-info { position: absolute; bottom: 1mm; right: 2mm; font-size: 6pt; color: #666; }
        .ref-barcode { display: flex; align-items: center; margin: 1mm 0; font-size: 12pt; }
        .product-title { font-size: 16pt; margin: 0; line-height: 1.1; max-height: 12mm; overflow: hidden; }
        .product-image { max-height: 22mm; max-width: 55mm; object-fit: contain; }
        .section-title { font-size: 10pt; margin: 2mm 0 1mm 0; font-weight: bold; }
        .page-break { page-break-after: always; width: 100%; height: 0; }
        @media print {
            body { width: 297mm; height: 210mm; margin: 0; padding: 2mm; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .product-card { border: 0.5px solid #ccc; }
        }
    </style>
    <title>Karty produktów Bricoman</title>
</head>
<body>';

        $product_count = count($products_data);
        
        for ($i = 0; $i < $product_count; $i++) {
            if ($i % 2 == 0 && $i > 0) {
                $html .= '<div class="page-break"></div></div><div class="page">';
            } elseif ($i % 2 == 0) {
                $html .= '<div class="page">';
            }
            
            $data = $products_data[$i];
            
            // --- LOGIKA FILTROWANIA CECH ---
            $filtered_attributes = [];
            if (!empty($data['attributes_list_object'])) {
                foreach ($data['attributes_list_object'] as $attr) {
                    $label = trim($attr['label']);
                    
                    if ($allowed_features !== null) {
                        // Jeśli podano dozwolone cechy, sprawdzamy czy ta jest na liście
                        if (in_array($label, $allowed_features)) {
                            $filtered_attributes[] = $attr;
                        }
                    } else {
                        // Stara logika
                        $is_excluded = false;
                        foreach ($this->excluded_features as $ex) {
                            if (stripos($label, $ex) !== false) $is_excluded = true;
                        }
                        if (!$is_excluded) $filtered_attributes[] = $attr;
                    }
                }
            }
            
            $barcode_url = $this->generateBarcode($data['main_sku'][0]);
            
            $html .= '
<div class="product-card">
    <div class="top-border"></div>

    <table style="width: 100%;">
        <tr>
            <td style="width:70%; vertical-align: top;">
                <h1 class="product-title">' . htmlspecialchars($data['title'][0]) . '</h1>
            </td>
            <td style="width:30%; text-align: right; height: 25mm;">';
            
            if (!empty($data['product_picture'])) {
                $html .= '<img class="product-image" src="' . htmlspecialchars($data['product_picture']) . '" />';
            }
            
            $html .= '</td>
        </tr>
    </table>

    <table style="width: 100%; margin-top: 2mm;">
        <tr>
            <td style="width:60%; vertical-align: bottom;">
                <div class="ref-barcode">
                    <strong>Nr ref.:  ' . htmlspecialchars($data['main_sku'][0]) . '</strong>
                    <img class="barcode" src="' . $barcode_url . '" alt="" />
                </div>
            </td>
            <td style="width:40%; text-align: center; vertical-align: top;">';
            
            if (!empty($data['product_brand'])) {
                $html .= '<img class="brand-picture" src="' . htmlspecialchars($data['product_brand']) . '" />';
            }
            
            $html .= '</td>
        </tr>
    </table>

    <div class="midle-border"></div>

    <h2 class="section-title">CECHY PRODUKTU</h2>';
    
    if (!empty($data['pictograms'])) {
                $html .= '
    <div class="pictograms-container">';
                
                foreach ($data['pictograms'] as $pictogram) {
                    $html .= '
        <img class="pictogram" src="' . htmlspecialchars($pictogram) . '" alt="Piktogram" />';
                }
                
                $html .= '
    </div>';
            }

            if (!empty($filtered_attributes)) {
                $html .= '
    <table class="table_product_data">
        <tr style="background-color: #6c6c6c;">
            <td style="height: 0.5mm;"></td>
            <td style="height: 0.5mm;"></td>
        </tr>';
        
                foreach ($filtered_attributes as $index => $attribute) {
                    $bg_color = ($index % 2 == 0) ? '#ffffff' : '#f2f2f2';
                    $html .= '
        <tr style="background-color: ' . $bg_color . ';">
            <td class="title_data">' . $attribute['label'] . '</td>
            <td class="value_data">' . $attribute['value'] . '</td>
        </tr>';
                }
        
                $html .= '
        <tr style="background-color: #6c6c6c;">
            <td style="height: 0.5mm;"></td>
            <td style="height: 0.5mm;"></td>
        </tr>
    </table>';
            } else {
                $html .= '
    <p style="color: #999; font-style: italic; margin: 2mm 0; font-size: 8pt;">
        Brak danych technicznych do wyświetlenia dla tego produktu (lub wszystkie zostały odfiltrowane).
    </p>';
            }
            
            $html .= '
    <div class="print-info">
        Data wydruku: ' . $data['print_date'] . ' r. ' . $data['print_hour'] . '
    </div>
</div>';

            if ($i == $product_count - 1 && $product_count % 2 != 0) {
                $html .= '<div class="product-card" style="border: 1px dashed #ccc; background: #f9f9f9;"></div>';
            }
        }
        
        $html .= '</div></body></html>';
        
        return $html;
    }
}

// ==========================================
// 3. LOGIKA OBSŁUGI ŻĄDAŃ
// ==========================================

$scraper = new BricomanProductScraper();
$profileManager = new ProfileManager();
$step = 1;
$available_features = [];
$error_message = '';
$success_message = '';

// AJAX: Obsługa pobierania profilu
if (isset($_POST['action']) && $_POST['action'] === 'get_profile') {
    $profiles = $profileManager->getProfiles();
    $name = $_POST['profile_name'] ?? '';
    echo json_encode($profiles[$name] ?? []);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // KROK 1: Analiza produktów
    if (isset($_POST['analyze_products'])) {
        $reference_numbers_input = trim($_POST['reference_numbers'] ?? '');
        
        if (!empty($reference_numbers_input)) {
            $reference_numbers = preg_split('/[\s,\n]+/', $reference_numbers_input, -1, PREG_SPLIT_NO_EMPTY);
            $reference_numbers = array_map('trim', $reference_numbers);
            $reference_numbers = array_unique($reference_numbers);
            
            $products_data = [];
            $found_refs = [];
            $all_features_found = [];
            
            if (count($reference_numbers) > 0) {
                foreach ($reference_numbers as $reference_number) {
                    $extracted_reference = $reference_number;
                    if (preg_match('/^https?:\/\//i', $reference_number)) {
                        $product_url = trim($reference_number);
                        if (preg_match_all('/(\d+)/', $product_url, $ref_matches)) {
                            $extracted_reference = end($ref_matches[1]);
                        }
                    } else {
                        $product_url = $scraper->findProductByReference($reference_number);
                    }

                    if ($product_url) {
                        $product_data = $scraper->getProductData($product_url, $extracted_reference);
                        if (!isset($product_data['error'])) {
                            $products_data[] = $product_data;
                            $found_refs[] = $extracted_reference;
                            
                            // Zbieramy wszystkie znalezione cechy do późniejszego wyboru
                            if(!empty($product_data['attributes_list_object'])) {
                                foreach($product_data['attributes_list_object'] as $attr) {
                                    $all_features_found[] = trim($attr['label']);
                                }
                            }
                        }
                    }
                    usleep(300000); // Małe opóźnienie dla serwera
                }
                
                if (!empty($products_data)) {
                    // Zapisujemy dane do sesji
                    $_SESSION['scraped_products'] = $products_data;
                    $_SESSION['found_refs'] = $found_refs;
                    
                    $available_features = array_unique($all_features_found);
                    sort($available_features);
                    $step = 2; // Przechodzimy do kroku 2
                } else {
                    $error_message = "Nie znaleziono żadnych produktów. Sprawdź numery referencyjne.";
                }
            }
        } else {
            $error_message = "Proszę podać numery referencyjne.";
        }
    }
    
    // KROK 2: Generowanie pliku na podstawie wybranych cech
    elseif (isset($_POST['generate_pdf'])) {
        if (isset($_SESSION['scraped_products'])) {
            $selected_features = $_POST['selected_features'] ?? [];
            $products_data = $_SESSION['scraped_products'];
            
            // Zapis profilu jeśli podano nazwę
            if (!empty($_POST['save_profile_name'])) {
                $profileManager->saveProfile(trim($_POST['save_profile_name']), $selected_features);
                $success_message = "Profil został zapisany. ";
            }
            
            // Generujemy HTML z filtrowaniem cech
            $html_output = $scraper->generateMultiProductHtmlTemplate($products_data, $selected_features);
            
            $filename = $scraper->generateFilename($_SESSION['found_refs']);
            $filepath = $scraper->getFilesDirectory() . '/' . $filename;
            
            if (file_put_contents($filepath, $html_output)) {
                $success_message .= "Plik wygenerowany pomyślnie!";
                
                // Pozostajemy w kroku 2, odtwarzamy listę cech
                $step = 2;
                $all_features_found = [];
                foreach($products_data as $p) {
                    if(!empty($p['attributes_list_object'])) {
                        foreach($p['attributes_list_object'] as $a) $all_features_found[] = trim($a['label']);
                    }
                }
                $available_features = array_unique($all_features_found);
                sort($available_features);
                
                $download_link = $scraper->getFilesDirectory() . '/' . $filename;
            } else {
                $error_message = "Błąd zapisu pliku na serwerze.";
            }
        } else {
            $error_message = "Sesja wygasła. Rozpocznij od nowa.";
            $step = 1;
        }
    }
    
    // Usuwanie profilu
    elseif (isset($_POST['delete_profile'])) {
        $profileManager->deleteProfile($_POST['profile_name']);
        
        // Jeśli jesteśmy w trakcie edycji (krok 2), odświeżamy widok
        if (isset($_SESSION['scraped_products'])) {
            $step = 2;
            $products_data = $_SESSION['scraped_products'];
            $all_features_found = [];
            foreach($products_data as $p) foreach($p['attributes_list_object'] as $a) $all_features_found[] = trim($a['label']);
            $available_features = array_unique($all_features_found);
            sort($available_features);
        }
    }
}

$recent_files = $scraper->getRecentFiles(10);
$profiles = $profileManager->getProfiles();

?>

<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title>MoKaTe - Moje Karty Techniczne</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f4f4f4; margin: 0; padding: 20px; color: #333; }
        .container { max-width: 1200px; margin: 0 auto; display: flex; gap: 20px; }
        .main-content { flex: 3; background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        .sidebar { flex: 1; }
        
        h1 { color: #e67e22; border-bottom: 2px solid #e67e22; padding-bottom: 10px; margin-top: 0; }
        h2 { font-size: 1.2em; margin-top: 20px; color: #555; }
        
        textarea { width: 100%; height: 120px; padding: 10px; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box; font-family: monospace; }
        
        .btn { padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; font-size: 1em; transition: 0.3s; display: inline-block; text-decoration: none; text-align: center;}
        .btn-primary { background: #e67e22; color: white; }
        .btn-primary:hover { background: #d35400; }
        .btn-secondary { background: #7f8c8d; color: white; }
        .btn-danger { background: #e74c3c; color: white; padding: 5px 10px; font-size: 0.8em; margin-left: 10px;}
        
        .alert { padding: 15px; margin-bottom: 20px; border-radius: 4px; border-left: 5px solid; }
        .alert-error { background: #fadbd8; color: #c0392b; border-color: #e74c3c; }
        .alert-success { background: #d4efdf; color: #27ae60; border-color: #2ecc71; }
        
        /* Grid dla cech */
        .features-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 8px; margin: 15px 0; max-height: 400px; overflow-y: auto; padding: 10px; border: 1px solid #eee; background: #fafafa; }
        .feature-item { display: flex; align-items: flex-start; gap: 8px; font-size: 0.9em; padding: 5px; border-bottom: 1px solid #eee; }
        .feature-item:hover { background: #fff; }
        .feature-item input { margin-top: 3px; }
        
        .profile-bar { display: flex; align-items: center; gap: 10px; margin-bottom: 15px; background: #eee; padding: 10px; border-radius: 4px; }
        .select-profile { padding: 8px; flex-grow: 1; border: 1px solid #ccc; border-radius: 4px;}
        
        .actions-bar { display: flex; justify-content: space-between; align-items: center; margin-top: 20px; padding-top: 20px; border-top: 1px solid #eee; flex-wrap: wrap; gap: 10px; }
        .save-profile-box { display: flex; gap: 10px; align-items: center; }
        .save-profile-box input { padding: 8px; border: 1px solid #ccc; border-radius: 4px; width: 250px; }

        .recent-list { background: #fff; padding: 15px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); margin-bottom: 20px; }
        .file-item { padding: 10px; border-bottom: 1px solid #eee; }
        .file-item:last-child { border: 0; }
        .file-name { font-weight: bold; color: #333; display: block; margin-bottom: 5px; word-break: break-all; }
        .file-meta { font-size: 0.8em; color: #777; }
        .download-btn { display: inline-block; margin-top: 5px; color: #e67e22; text-decoration: none; font-size: 0.9em; font-weight: bold; }
        
        .links-bar { margin-bottom: 10px; }
        .links-bar button { background: none; border: none; color: #007bff; text-decoration: underline; cursor: pointer; padding: 0; font-size: 0.9em; margin-right: 15px; }
    </style>
</head>
<body>

<div class="container">
    <div class="main-content">
        <h1>MoKaTe - Moje Karty Techniczne</h1>
        
        <?php if ($error_message): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error_message) ?></div>
        <?php endif; ?>
        
        <?php if ($success_message): ?>
            <div class="alert alert-success">
                <?= htmlspecialchars($success_message) ?>
                <?php if (isset($download_link)): ?>
                    <br><br>
                    <a href="<?= htmlspecialchars($download_link) ?>" class="btn btn-primary" download>⬇ POBIERZ WYGENEROWANY PLIK</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($step === 1): ?>
            <form method="POST">
                <h2>Krok 1: Wprowadź produkty</h2>
                <p>Wpisz numery referencyjne (np. 123456) lub linki do produktów, każdy w nowej linii:</p>
                <textarea name="reference_numbers" required><?= isset($_POST['reference_numbers']) ? htmlspecialchars($_POST['reference_numbers']) : '' ?></textarea>
                <br><br>
                <button type="submit" name="analyze_products" class="btn btn-primary">Analizuj i przejdź do wyboru cech >></button>
            </form>
        <?php endif; ?>

        <?php if ($step === 2): ?>
            <form method="POST" id="generateForm">
                <h2>Krok 2: Konfiguracja wydruku</h2>
                
                <div class="profile-bar">
                    <strong>Wczytaj profil:</strong>
                    <select id="profileSelect" class="select-profile">
                        <option value="">-- Wybierz profil --</option>
                        <?php foreach ($profiles as $pName => $pFeatures): ?>
                            <option value="<?= htmlspecialchars($pName) ?>"><?= htmlspecialchars($pName) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" class="btn btn-secondary" onclick="loadProfile()">Wczytaj</button>
                    
                    <?php if(!empty($profiles)): ?>
                         <button type="button" class="btn btn-danger" onclick="deleteProfileCurrent()">Usuń profil</button>
                    <?php endif; ?>
                </div>

                <div class="links-bar">
                    <button type="button" onclick="toggleAll(true)">Zaznacz wszystkie</button>
                    <button type="button" onclick="toggleAll(false)">Odznacz wszystkie</button>
                </div>

                <div class="features-grid">
                    <?php if(empty($available_features)): ?>
                        <p style="padding:10px; color:#777;">Nie znaleziono żadnych cech technicznych dla pobranych produktów.</p>
                    <?php else: ?>
                        <?php foreach ($available_features as $feature): ?>
                            <label class="feature-item">
                                <input type="checkbox" name="selected_features[]" value="<?= htmlspecialchars($feature) ?>" class="feature-checkbox">
                                <span><?= htmlspecialchars($feature) ?></span>
                            </label>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <div class="actions-bar">
                    <div class="save-profile-box">
                        <input type="text" name="save_profile_name" placeholder="Nazwa profilu (zostaw puste by nie zapisywać)">
                    </div>
                    
                    <div>
                        <a href="index.php" class="btn btn-secondary">Anuluj / Nowe wyszukiwanie</a>
                        <button type="submit" name="generate_pdf" class="btn btn-primary">Generuj Kartę Techniczną</button>
                    </div>
                </div>
            </form>
            
            <form method="POST" id="deleteProfileForm" style="display:none;">
                <input type="hidden" name="delete_profile" value="1">
                <input type="hidden" name="profile_name" id="deleteProfileName">
            </form>
        <?php endif; ?>
    </div>

    <div class="sidebar">
        <div class="recent-list">
            <h3>Ostatnie pliki</h3>
            <?php if (empty($recent_files)): ?>
                <div class="file-meta">Brak plików</div>
            <?php else: ?>
                <?php foreach ($recent_files as $file): ?>
                    <div class="file-item">
                        <span class="file-name"><?= htmlspecialchars($file['filename']) ?></span>
                        <div class="file-meta"><?= $file['date'] ?> • <?= $file['size'] ?></div>
                        <a href="<?= htmlspecialchars($file['url']) ?>" class="download-btn" download>Pobierz</a>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        
        <?php if (!empty($profiles)): ?>
        <div class="recent-list">
            <h3>Twoje Profile</h3>
            <ul style="padding-left: 20px; margin: 0;">
                <?php foreach ($profiles as $name => $feats): ?>
                    <li style="font-size: 0.9em; color: #555; margin-bottom: 5px;">
                        <strong><?= htmlspecialchars($name) ?></strong><br>
                        (<?= count($feats) ?> zapisanych cech)
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
function toggleAll(checked) {
    document.querySelectorAll('.feature-checkbox').forEach(cb => cb.checked = checked);
}

function loadProfile() {
    const profileName = document.getElementById('profileSelect').value;
    if (!profileName) return;

    const formData = new FormData();
    formData.append('action', 'get_profile');
    formData.append('profile_name', profileName);

    fetch('index.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(features => {
        if (!Array.isArray(features)) {
            alert('Błąd wczytywania profilu');
            return;
        }
        
        // Najpierw odznaczamy wszystko
        toggleAll(false);
        
        // Zaznaczamy to co w profilu
        let count = 0;
        const checkboxes = document.querySelectorAll('.feature-checkbox');
        checkboxes.forEach(cb => {
            // Dekodujemy HTML entities (na wypadek dziwnych znaków)
            const val = cb.value;
            if (features.includes(val)) {
                cb.checked = true;
                count++;
            }
        });
        alert('Wczytano profil. Zaznaczono cech: ' + count);
    })
    .catch(err => {
        console.error(err);
        alert('Wystąpił błąd podczas wczytywania profilu.');
    });
}

function deleteProfileCurrent() {
    const profileName = document.getElementById('profileSelect').value;
    if (!profileName) {
        alert('Wybierz profil z listy, aby go usunąć.');
        return;
    }
    if(confirm('Czy na pewno chcesz bezpowrotnie usunąć profil: ' + profileName + '?')) {
        document.getElementById('deleteProfileName').value = profileName;
        document.getElementById('deleteProfileForm').submit();
    }
}
</script>

</body>
</html>
