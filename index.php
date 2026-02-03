<?php
session_start();

// ==========================================
// 1. MANAGER PROFILI
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
// 2. SCRAPER
// ==========================================
class BricomanProductScraper {
    private $base_url = "https://www.bricoman.pl";
    private $sitemap_index_url = "https://www.bricoman.pl/pub/media/sitemap/products.xml";
    private $sitemap_cache_dir = 'sitemap_cache';
    private $sitemap_cache_duration = 86400;
    private $pictograms = [];
    
    private $excluded_features = [
        'Kraj odpowiedzialnego podmiotu gospodarczego produktu w UE',
        'Głębokość transport', 'Wysokość transport', 'Szerokość transport',
        'Rodzina kolorów', 'Kolor rodzina', 'Kod dostawcy', 'Referencja dostawcy',
        'Styl płytek', 'Rektyfikacja [tak/nie]', 'Grupa wymiarowa',
        'Funkcja antypoślizgowa', 'Odporność na zużycie', 'Kolor',
        'Jednostka pojemności ', 'Gama kolorystyczna'
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
    
    // --- SITEMAP LOGIC ---

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
                    if ($this->downloadAndCacheSitemap($sitemap_url)) $updated_count++;
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
            error_log("Błąd pobierania sitemap: " . $e->getMessage());
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
        } catch (Exception $e) {
            error_log("Błąd w cleanupOldFiles: " . $e->getMessage());
        }
    }
    
    public function getRecentFiles($limit = 5) {
        try {
            $files = glob($this->files_directory . '/*.html');
            if (empty($files)) return [];
            usort($files, function($a, $b) { return filemtime($b) - filemtime($a); });
            $recent = [];
            foreach (array_slice($files, 0, $limit) as $file) {
                if (file_exists($file)) {
                    $recent[] = [
                        'filename' => basename($file), 
                        'path' => $file,
                        'size' => $this->formatFileSize(filesize($file)), 
                        'date' => date('d.m.Y H:i', filemtime($file)),
                        'url' => $this->files_directory . '/' . basename($file)
                    ];
                }
            }
            return $recent;
        } catch (Exception $e) {
            error_log("Błąd w getRecentFiles: " . $e->getMessage());
            return [];
        }
    }
    
    private function formatFileSize($bytes) {
        if ($bytes == 0) return '0 B';
        $u = ['B', 'KB', 'MB', 'GB']; 
        $i = floor(log($bytes, 1024));
        return round($bytes / pow(1024, $i), 2) . ' ' . $u[$i];
    }
    
    public function generateFilename($refs) {
        $name = implode('_', array_slice($refs, 0, 3));
        if (count($refs) > 3) $name .= '_i_inne';
        return "ref_" . substr(preg_replace('/[^a-zA-Z0-9_-]/', '_', $name), 0, 100) . "_.html";
    }

    // --- SEARCH & PARSE ---
    
    public function findProductByReference($ref) {
        $this->ensureSitemapsUpdated();
        foreach ($this->getCachedSitemapUrls() as $file) {
            try {
                if ($url = $this->searchInCachedSitemap($file, $ref)) return $url;
            } catch (Exception $e) {
                error_log("Błąd w searchInCachedSitemap: " . $e->getMessage());
            }
        }
        return $this->findProductOnline($ref);
    }
    
    private function findProductOnline($ref) {
        try {
            $idx = $this->makeRequest($this->sitemap_index_url);
            if (!$idx) return null;
            preg_match_all('/<loc>(.*?)<\/loc>/', $idx, $m);
            foreach ($m[1] as $url) {
                if ($pUrl = $this->searchInSitemap($url, $ref)) {
                    $this->downloadAndCacheSitemap($url); 
                    return $pUrl;
                }
            }
            return null;
        } catch (Exception $e) {
            error_log("Błąd w findProductOnline: " . $e->getMessage());
            return null;
        }
    }
    
    private function searchInSitemapContent($xml, $ref) {
        if (!$xml) return null;
        if (preg_match_all('/<loc>(.*?' . preg_quote($ref, '/') . '.*?)<\/loc>/', $xml, $m)) {
            foreach ($m[1] as $u) if (strpos($u, $ref) !== false) return trim($u);
        }
        return null;
    }
    
    private function searchInCachedSitemap($file, $ref) { 
        $xml_content = file_get_contents($file);
        return $this->searchInSitemapContent($xml_content, $ref); 
    }
    
    private function searchInSitemap($url, $ref) { 
        $xml_content = $this->makeRequest($url);
        return $this->searchInSitemapContent($xml_content, $ref); 
    }
    
    public function getProductData($url, $ref) {
        try {
            $html = $this->makeRequest($url);
            if (!$html) return ["error" => "Nie udało się pobrać strony produktu"];
            return $this->parseProductPage($html, $url, $ref);
        } catch (Exception $e) {
            return ["error" => "Błąd przy pobieraniu danych: " . $e->getMessage()];
        }
    }
    
    private function makeRequest($url) {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36\r\n" .
                           "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8\r\n" .
                           "Accept-Language: pl-PL,pl;q=0.9,en-US;q=0.8,en;q=0.7\r\n",
                'timeout' => 15,
                'ignore_errors' => true
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false
            ]
        ]);
        
        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            error_log("HTTP request failed for URL: " . $url);
            return false;
        }
        return $response;
    }

    private function parseProductPage($html, $url, $ref) {
        $data = [
            'title' => ['Produkt Bricoman'], 
            'main_sku' => [$ref], 
            'product_picture' => null, 
            'product_brand' => null, 
            'attributes_list_object' => $this->extractTechnicalSpecifications($html),
            'pictograms' => [], 
            'print_date' => date('d.m.Y'), 
            'print_hour' => date('H:i')
        ];
        
        if (preg_match('/<h1[^>]*>(.*?)<\/h1>/i', $html, $m)) {
            $data['title'][0] = strip_tags(trim($m[1]));
        }
        
        // Obrazek
        $data['product_picture'] = $this->extractProductPicture($html, $ref);
        if (!$data['product_picture']) {
             $image_patterns = [
                '/<img[^>]*class="[^"]*b-product-carousel__main-slide swiper-slide[^"]*"[^>]*src="([^"]*\.(jpg|jpeg|png|gif))"[^>]*>/i',
                '/<img[^>]*class="[^"]*b-product-carousel__main-slide swiper-slide[^"]*"[^>]*data-src="([^"]*\.(jpg|jpeg|png|gif))"[^>]*>/i',
                '/<div[^>]*class="[^"]*b-product-carousel__main-slide-image[^"]*"[^>]*>.*?<img[^>]*src="([^"]*\.(jpg|jpeg|png|gif))"[^>]*>/is',
                '/<img[^>]*class="[^"]*main-slide[^"]*"[^>]*src="([^"]*)"/i'
            ];
            foreach ($image_patterns as $pattern) {
                if (preg_match($pattern, $html, $m)) {
                    $data['product_picture'] = $this->normalizeUrl($m[1]);
                    break;
                }
            }
        }
        
        // Brand
        $brand_patterns = [
            '/<img[^>]*class="[^"]*b-product-carousel__main-brand-image[^"]*"[^>]*src="([^"]*)"[^>]*>/i',
            '/<img[^>]*class="[^"]*b-product-carousel__main-brand-image[^"]*"[^>]*data-src="([^"]*)"[^>]*>/i',
            '/<div[^>]*class="[^"]*b-product-carousel__main-brand[^"]*"[^>]*>.*?<img[^>]*src="([^"]*)"[^>]*>/is',
            '/<img[^>]*class="[^"]*brand-image[^"]*"[^>]*src="([^"]*)"/i',
            '/<img[^>]*class="[^"]*brand-image[^"]*"[^>]*data-src="([^"]*)"/i'
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
    
    private function extractProductPicture($html, $ref) {
        $pattern = '/<img[^>]*(?:src|data-src)="([^"]*' . preg_quote($ref, '/') . '[^"]*_picture\.jpeg[^"]*)"[^>]*>/i';
        if (preg_match($pattern, $html, $m)) {
            return $this->normalizeUrl($this->cleanImageUrl($m[1]));
        }
        
        $patterns = [
            '/<img[^>]*data-src="([^"]*' . preg_quote($ref, '/') . '[^"]*_picture\.jpeg[^"]*)"[^>]*>/i',
            '/<img[^>]*src="([^"]*' . preg_quote($ref, '/') . '[^"]*_picture\.jpeg[^"]*)"[^>]*>/i',
            '/<div[^>]*data-image="([^"]*' . preg_quote($ref, '/') . '[^"]*_picture\.jpeg[^"]*)"[^>]*>/i',
            '/<img[^>]*data-src="([^"]*' . preg_quote($ref, '/') . '[^"]*_picture_01\.jpeg[^"]*)"[^>]*>/i',
            '/<img[^>]*src="([^"]*' . preg_quote($ref, '/') . '[^"]*_picture_01\.jpeg[^"]*)"[^>]*>/i'
        ];
        
        foreach ($patterns as $p) {
            if (preg_match($p, $html, $m)) {
                $url = $this->normalizeUrl($this->cleanImageUrl($m[1]));
                if ($this->checkImageExists($url)) return $url;
            }
        }
        return null;
    }
    
    private function cleanImageUrl($url) { 
        if (strpos($url, '.jpeg?') !== false) $url = substr($url, 0, strpos($url, '.jpeg?') + 5);
        if (strpos($url, '.jpg?') !== false) $url = substr($url, 0, strpos($url, '.jpg?') + 4);
        return explode('?', $url)[0]; 
    }
    
    private function checkImageExists($url) { 
        $headers = @get_headers($url); 
        return ($headers && strpos($headers[0], '200') !== false); 
    }
    
    private function normalizeUrl($url) { 
        if(!$url) return null; 
        if(strpos($url,'//')===0) return 'https:'.$url; 
        if(strpos($url,'http')===0) return $url;
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
        if (preg_match('/<h3[^>]*>[^<]*Cechy produktu[^<]*<\/h3>(.*?)<(h3|div|section)/is', $html, $sec)) {
            if (preg_match_all('/<li[^>]*>(.*?)<\/li>/is', $sec[1], $lis)) {
                foreach ($lis[1] as $li) {
                    $spec = $this->parseFeatureItem($li);
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
    
    // Helpery do parsowania
    private function parseFeatureItem($li) {
        $text = strip_tags($li, '<img>');
        $text = preg_replace('/<img[^>]*>/', '', $text);
        $text = trim($text);
        if (!empty($text)) {
            if (strpos($text, ':') !== false) {
                list($l, $v) = explode(':', $text, 2);
                return ['label' => htmlspecialchars(trim($l)), 'value' => htmlspecialchars(trim($v))];
            } else {
                return ['label' => 'Cecha', 'value' => htmlspecialchars($text)];
            }
        }
        return null;
    }

    private function parseTableRow($row) {
        if (preg_match_all('/<t(d|h)[^>]*>(.*?)<\/t(d|h)>/is', $row, $cols)) {
            if (count($cols[2]) >= 2) {
                $l = trim(strip_tags($cols[2][0]));
                $v = trim(strip_tags($cols[2][1]));
                $l = preg_replace('/\s+/', ' ', $l);
                $v = preg_replace('/\s+/', ' ', $v);
                if (!empty($l) && !empty($v) && $l !== $v) {
                    return ['label' => htmlspecialchars($l), 'value' => htmlspecialchars($v)];
                }
            }
        }
        return null;
    }

    private function parseSpecificationItem($item) {
        if (preg_match('/<span[^>]*class="[^"]*spec-name[^"]*"[^>]*>(.*?)<\/span>/is', $item, $l_m) &&
            preg_match('/<span[^>]*class="[^"]*spec-value[^"]*"[^>]*>(.*?)<\/span>/is', $item, $v_m)) {
            $label = trim(strip_tags($l_m[1]));
            $value = trim(strip_tags($v_m[1]));
            if (!empty($label) && !empty($value)) {
                return ['label' => htmlspecialchars($label), 'value' => htmlspecialchars($value)];
            }
        }
        return null;
    }
    
    private function generateBarcode($code) {
        return "https://barcode.tec-it.com/barcode.ashx?data=" . urlencode($code) . "&code=Code128&dpi=150&format=png&unit=px&height=50&width=300&hidehrt=TRUE";
    }
    
    public function getExcludedFeatures() {
        return $this->excluded_features;
    }

    // =============================================================
    // CSS FLEX
    // =============================================================
    public function generateMultiProductHtmlTemplate($products_data, $allowed_features = null, $format = 'a5') {
        
        switch ($format) {
            case 'a4':
                $page_orientation = 'portrait';
                $card_width = '100%';
                $card_height = 'calc(100% - 2mm)'; 
                $items_per_page = 1;
                $base_font_size = '18pt'; 
                break;
            case 'a6':
                $page_orientation = 'portrait'; 
                $card_width = 'calc(50% - 1.0mm)';
                $card_height = 'calc(50% - 1.0mm)';
                $items_per_page = 4;
                $base_font_size = '8pt'; 
                break;
            case 'a5':
            default:
                $page_orientation = 'landscape';
                $card_width = 'calc(50% - 1.0mm)';
                $card_height = 'calc(100% - 1mm)';
                $items_per_page = 2;
                $base_font_size = '11pt';
                break;
        }

        $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>MoKaTe - Moje Karty Techniczne</title>
    <style>
        @page { size: A4 ' . $page_orientation . '; margin: 0; }
        body { 
            width: 297mm; height: 210mm; margin: 0; padding: 2mm; 
            font-family: Arial, sans-serif; 
            box-sizing: border-box; 
        }
        ' . ($page_orientation == 'portrait' ? 'body { width: 210mm; height: 297mm; }' : '') . '

        .page { width: 100%; height: 100%; display: flex; flex-wrap: wrap; gap: 2mm; align-content: flex-start; }
        
        .product-card { 
            width: ' . $card_width . '; 
            height: ' . $card_height . '; 
            border: 1px solid #ddd; 
            padding: 1em; 
            box-sizing: border-box; 
            position: relative; 
            page-break-inside: avoid;
            background: white;
            font-size: ' . $base_font_size . ';
            display: flex;
            flex-direction: column;
        }

        .card-header {
            height: 25%;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            margin-bottom: 0.5em;
        }

        .header-top-content {
            flex: 1; 
            display: flex;
            justify-content: space-between;
            min-height: 0;
            margin-bottom: 0.3em;
        }

        .header-title-box {
            width: 65%;
            padding-right: 0.5em;
        }
        
        h1.product-title { 
            font-size: 1.5em;
            margin: 0; 
            line-height: 1.1; 
            max-height: 100%; 
            overflow: hidden; 
        }

        .header-image-box {
            width: 35%;
            height: 100%;
            display: flex;
            justify-content: flex-end;
            align-items: flex-start;
        }

        .product-image { 
            max-height: 100%; 
            max-width: 100%; 
            object-fit: contain; 
        }

        .ref-row {
            height: 2.5em;
            display: flex; 
            align-items: center; 
            font-size: 1em;
            flex-shrink: 0; 
        }

        .barcode { height: 0.8em; margin-left: 0.5em; }
        .brand-picture { max-height: 2.5em; max-width: 6em; object-fit: contain; }

        .card-body {
            flex: 1; 
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .top-border { background-color: #da7625; height: 2mm; margin-bottom: 0.5em; flex-shrink: 0; }
        .midle-border { background-color: #da7625; height: 1mm; margin: 0.5em 0; flex-shrink: 0; }
        
        .section-title { font-size: 1em; font-weight: bold; margin-bottom: 0.3em; display: block; flex-shrink: 0; }

        .pictograms-container { 
            display: flex; flex-wrap: wrap; gap: 0.2em; padding: 0.2em; 
            background: #f9f9f9; border: 1px solid #da7625; 
            min-height: 3em; flex-shrink: 0; margin-bottom: 0.5em;
        }
        .pictogram { width: 4.5em; height: 4.5em; object-fit: contain; background: white; padding: 0.1em; }
        
        .scrollable-specs {
            flex: 1;
            overflow: hidden; 
        }

        .table_product_data { border-collapse: collapse; width: 100%; font-size: 0.85em; }
        .table_product_data td { border: 1px solid #6c6c6c; padding: 0.2em; }
        .title_data { width: 40%; font-weight: bold; background-color: #f0f0f0; }
        
        .print-info { 
            position: absolute; bottom: 2mm; right: 2mm; 
            font-size: 0.6em; color: #666; 
        }
        .page-break { page-break-after: always; width: 100%; height: 0; margin: 0; }
    </style>
</head>
<body>';

        $product_count = count($products_data);
        $html .= '<div class="page">';

        for ($i = 0; $i < $product_count; $i++) {
            if ($i > 0 && $i % $items_per_page == 0) {
                $html .= '</div><div class="page-break"></div><div class="page">';
            }
            
            $data = $products_data[$i];
            
            $filtered_attributes = [];
            if (!empty($data['attributes_list_object'])) {
                foreach ($data['attributes_list_object'] as $attr) {
                    $label = trim($attr['label']);
                    if ($allowed_features !== null) {
                        if (in_array($label, $allowed_features)) $filtered_attributes[] = $attr;
                    } else {
                        $is_excluded = false;
                        foreach ($this->excluded_features as $ex) if (stripos($label, $ex) !== false) $is_excluded = true;
                        if (!$is_excluded) $filtered_attributes[] = $attr;
                    }
                }
            }
            
            $barcode_url = $this->generateBarcode($data['main_sku'][0]);
            
            $html .= '<div class="product-card">
                <div class="top-border"></div>
                
                <div class="card-header">
                    <div class="header-top-content">
                        <div class="header-title-box">
                             <h1 class="product-title">' . htmlspecialchars($data['title'][0]) . '</h1>
                        </div>
                        <div class="header-image-box">
                             ' . (!empty($data['product_picture']) ? '<img class="product-image" src="' . htmlspecialchars($data['product_picture']) . '" />' : '') . '
                        </div>
                    </div>

                    <div class="ref-row">
                        <strong>Nr ref.: ' . htmlspecialchars($data['main_sku'][0]) . '</strong>
                        <img class="barcode" src="' . $barcode_url . '" />
                        <div style="flex:1; text-align:right;">
                             ' . (!empty($data['product_brand']) ? '<img class="brand-picture" src="' . htmlspecialchars($data['product_brand']) . '" />' : '') . '
                        </div>
                    </div>
                </div>
                
                <div class="midle-border"></div>
                
                <div class="card-body">
                    <span class="section-title">CECHY PRODUKTU</span>';

            if (!empty($data['pictograms'])) {
                $html .= '<div class="pictograms-container">';
                foreach ($data['pictograms'] as $pic) {
                    $html .= '<img class="pictogram" src="' . htmlspecialchars($pic) . '" />';
                }
                $html .= '</div>';
            }

            $html .= '<div class="scrollable-specs">';
            if (!empty($filtered_attributes)) {
                $html .= '<table class="table_product_data">
                    <tr style="background-color: #6c6c6c;"><td colspan="2" style="height: 1px; padding:0;"></td></tr>';
                foreach ($filtered_attributes as $idx => $attr) {
                    $bg = ($idx % 2 == 0) ? '#ffffff' : '#f0f0f0';
                    $html .= '<tr style="background-color: ' . $bg . ';">
                        <td class="title_data">' . $attr['label'] . '</td>
                        <td>' . $attr['value'] . '</td>
                    </tr>';
                }
                $html .= '<tr style="background-color: #6c6c6c;"><td colspan="2" style="height: 1px; padding:0;"></td></tr>
                </table>';
            } else {
                $html .= '<p style="color:#999;font-style:italic; font-size:0.9em;">Brak wybranych cech.</p>';
            }
            $html .= '</div>';
            
            $html .= '<div class="print-info">Data: ' . $data['print_date'] . ' ' . $data['print_hour'] . '</div>
                </div>';
            
            $html .= '</div>';
        }
        
        $html .= '</div></body></html>';
        return $html;
    }
}

// ==========================================
// 3. APLIKACJA
// ==========================================

$scraper = new BricomanProductScraper();
$profileManager = new ProfileManager();
$step = 1;
$available_features = [];
$error_message = '';
$success_message = '';

if (isset($_POST['action']) && $_POST['action'] === 'get_profile') {
    $profiles = $profileManager->getProfiles();
    echo json_encode($profiles[$_POST['profile_name'] ?? ''] ?? []);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
                    $_SESSION['scraped_products'] = $products_data;
                    $_SESSION['found_refs'] = $found_refs;
                    
                    $available_features = array_unique($all_features_found);
                    sort($available_features);
                    $step = 2;
                } else {
                    $error_message = "Nie znaleziono żadnych produktów. Sprawdź numery referencyjne.";
                }
            }
        } else {
            $error_message = "Proszę podać numery referencyjne.";
        }
    }
    elseif (isset($_POST['generate_pdf'])) {
        if (isset($_SESSION['scraped_products'])) {
            $selected = $_POST['selected_features'] ?? [];
            $products = $_SESSION['scraped_products'];
            $format = $_POST['page_format'] ?? 'a5';
            
            if (!empty($_POST['save_profile_name'])) {
                $profileManager->saveProfile(trim($_POST['save_profile_name']), $selected);
                $success_message = "Profil został zapisany. ";
            }
            
            $html = $scraper->generateMultiProductHtmlTemplate($products, $selected, $format);
            $file = $scraper->generateFilename($_SESSION['found_refs']);
            $path = $scraper->getFilesDirectory() . '/' . $file;
            
            if (file_put_contents($path, $html)) {
                $success_message .= "Plik wygenerowany pomyślnie dla formatu $format! Możesz teraz pobrać i wydrukować plik.";
                
                $step = 2;
                $all_features_found = [];
                foreach($products as $p) {
                    if(!empty($p['attributes_list_object'])) {
                        foreach($p['attributes_list_object'] as $a) {
                            $all_features_found[] = trim($a['label']);
                        }
                    }
                }
                $available_features = array_unique($all_features_found);
                sort($available_features);
                
                $download_link = $scraper->getFilesDirectory() . '/' . $file;
            } else {
                $error_message = "Błąd zapisu pliku na serwerze.";
            }
        } else {
            $error_message = "Sesja wygasła. Rozpocznij od nowa.";
            $step = 1;
        }
    }
    elseif (isset($_POST['delete_profile'])) {
        $profileManager->deleteProfile($_POST['profile_name']);
        if (isset($_SESSION['scraped_products'])) {
            $step = 2;
            $products = $_SESSION['scraped_products'];
            $all_features_found = [];
            foreach($products as $p) {
                if(!empty($p['attributes_list_object'])) {
                    foreach($p['attributes_list_object'] as $a) {
                        $all_features_found[] = trim($a['label']);
                    }
                }
            }
            $available_features = array_unique($all_features_found);
            sort($available_features);
        }
    }
}

$recent = $scraper->getRecentFiles();
$profiles = $profileManager->getProfiles();
?>
<?php include '../log.php'; ?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title>MoKaTe - Moje Karty Techniczne</title>
    <style>
        body { font-family: 'Segoe UI', sans-serif; background: #f4f4f4; margin: 0; padding: 20px; color: #333; }
        .container { max-width: 1200px; margin: 0 auto; display: flex; gap: 20px; }
        .main { flex: 3; background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        .sidebar { flex: 1; }
        h1 { color: #e67e22; border-bottom: 2px solid #e67e22; padding-bottom: 10px; margin-top: 0; }
        
        .btn { padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; color: white; text-decoration: none; display: inline-block; font-size: 14px;}
        .btn-primary { background: #e67e22; }
        .btn-primary:hover { background: #d35400; }
        .btn-secondary { background: #7f8c8d; }
        .btn-danger { background: #e74c3c; padding: 5px 10px; font-size: 12px; margin-left: 10px;}
        
        .alert { padding: 15px; margin-bottom: 20px; border-radius: 4px; border-left: 5px solid; }
        .alert-success { background: #d4efdf; color: #27ae60; border-color: #2ecc71; }
        .alert-error { background: #fadbd8; color: #c0392b; border-color: #e74c3c; }
        
        textarea { width: 97%; height: 120px; padding: 10px; border: 1px solid #ddd; margin-bottom: 10px; font-family: monospace; }
        .features-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 10px; max-height: 400px; overflow-y: auto; padding: 10px; background: #fafafa; border: 1px solid #eee; margin: 15px 0; }
        .feature-item { display: flex; gap: 8px; padding: 5px; border-bottom: 1px solid #eee; font-size: 0.9em; align-items: flex-start; }
        .control-bar { background: #eee; padding: 15px; border-radius: 5px; margin-bottom: 20px; }
        .file-item { padding: 10px; border-bottom: 1px solid #eee; background: white; margin-bottom: 5px;}
        .file-name { font-weight: bold; display: block; word-break: break-all; }
        .file-meta { font-size: 12px; color: #777; }
        .format-selector { display: flex; gap: 15px; margin-top: 10px; align-items: center; }
        .format-option { cursor: pointer; display: flex; align-items: center; gap: 5px; font-weight: bold; }
        .format-option input { transform: scale(1.3); }
        
        .profile-bar { display: flex; align-items: center; gap: 10px; margin-bottom: 15px; }
        .select-profile { padding: 8px; flex-grow: 1; border: 1px solid #ccc; border-radius: 4px;}
        .actions-bar { display: flex; justify-content: space-between; align-items: center; margin-top: 20px; padding-top: 20px; border-top: 1px solid #eee; flex-wrap: wrap; gap: 10px; }
        .save-profile-box { display: flex; gap: 10px; align-items: center; }
        .save-profile-box input { padding: 8px; border: 1px solid #ccc; border-radius: 4px; width: 250px; }
        .links-bar { margin-bottom: 10px; }
        .links-bar button { background: none; border: none; color: #007bff; text-decoration: underline; cursor: pointer; padding: 0; font-size: 0.9em; margin-right: 15px; }
        .download-btn { display: inline-block; margin-top: 5px; color: #e67e22; text-decoration: none; font-size: 0.9em; font-weight: bold; }
    </style>
</head>
<body>

<div class="container">
    <div class="main">
        <h1>MoKaTe - Moje Karty Techniczne</h1>
        
        <?php if ($error_message): ?> 
            <div class="alert alert-error"><?= htmlspecialchars($error_message) ?></div> 
        <?php endif; ?>
        
        <?php if ($success_message): ?> 
            <div class="alert alert-success">
                <?= htmlspecialchars($success_message) ?>
                <?php if (isset($download_link)): ?> 
                    <br><br>
                    <a href="<?= htmlspecialchars($download_link) ?>" class="btn btn-primary" download>⬇ Pobierz plik</a> 
                <?php endif; ?>
            </div> 
        <?php endif; ?>

        <?php if ($step === 1): ?>
            <form method="POST">
                <h3>Krok 1: Produkty</h3>
                <p>Podaj numery referencyjne (np. 123456) lub linki do produktów, każdy w nowej linii:</p>
                <textarea name="reference_numbers" required><?= isset($_POST['reference_numbers']) ? htmlspecialchars($_POST['reference_numbers']) : '' ?></textarea>
                <button type="submit" name="analyze_products" class="btn btn-primary">Analizuj i przejdź do wyboru cech >></button>
            </form>
        <?php endif; ?>

        <?php if ($step === 2): ?>
            <form method="POST" id="generateForm">
                <h3>Krok 2: Opcje wydruku</h3>
                
                <div class="control-bar">
                    <div style="margin-bottom: 15px;">
                        <strong>Format karty:</strong>
                        <div class="format-selector">
                            <label class="format-option">
                                <input type="radio" name="page_format" value="a4"> A4 (1 produkt na str.)
                            </label>
                            <label class="format-option">
                                <input type="radio" name="page_format" value="a5" checked> A5 (2 produkty na str.)
                            </label>
                            <label class="format-option">
                                <input type="radio" name="page_format" value="a6"> A6 (4 produkty na str.)
                            </label>
                        </div>
                    </div>
                    <hr style="border:0; border-top:1px solid #ccc; margin:15px 0;">
                    
                    <div class="profile-bar">
                        <strong>Wczytaj profil:</strong>
                        <select id="profileSelect" class="select-profile">
                            <option value="">-- Wybierz --</option>
                            <?php foreach ($profiles as $name => $f): ?>
                                <option value="<?= htmlspecialchars($name) ?>"><?= htmlspecialchars($name) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" class="btn btn-secondary" onclick="loadProfile()">Wczytaj</button>
                        <?php if(!empty($profiles)): ?> 
                            <button type="button" class="btn btn-danger" onclick="deleteProfileCurrent()">Usuń</button> 
                        <?php endif; ?>
                    </div>
                </div>

                <div class="links-bar">
                    <button type="button" onclick="toggleAll(true)">Zaznacz wszystkie</button>
                    <button type="button" onclick="toggleAll(false)">Odznacz wszystkie</button>
                </div>

                <div class="features-grid">
                    <?php if(empty($available_features)): ?>
                        <p style="padding:10px; color:#777;">Nie znaleziono żadnych cech technicznych dla pobranych produktów.</p>
                    <?php else: ?>
                        <?php foreach ($available_features as $f): ?>
                            <label class="feature-item">
                                <input type="checkbox" name="selected_features[]" value="<?= htmlspecialchars($f) ?>" class="feature-checkbox">
                                <?= htmlspecialchars($f) ?>
                            </label>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <div class="actions-bar">
                    <div class="save-profile-box">
                        <input type="text" name="save_profile_name" placeholder="Nazwa profilu (zostaw puste by nie zapisywać)" style="padding:10px; border:1px solid #ccc; width:250px;">
                    </div>
                    
                    <div>
                        <a href="index.php" class="btn btn-secondary">Anuluj / Nowe wyszukiwanie</a>
                        <button type="submit" name="generate_pdf" class="btn btn-primary">GENERUJ KARTY</button>
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
        <div class="recent-list" style="background: #fff; padding: 15px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); margin-bottom: 20px;">
            <h3>Ostatnie pliki</h3>
            <?php if (empty($recent)): ?>
                <div class="file-meta">Brak plików</div>
            <?php else: ?>
                <?php foreach ($recent as $f): ?>
                    <div class="file-item">
                        <span class="file-name"><?= htmlspecialchars($f['filename']) ?></span>
                        <div class="file-meta"><?= $f['date'] ?> • <?= $f['size'] ?></div>
                        <a href="<?= htmlspecialchars($f['url']) ?>" class="download-btn" download>Pobierz</a>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        
        <?php if(!empty($profiles)): ?>
        <div class="recent-list" style="background: #fff; padding: 15px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.1);">
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
    const name = document.getElementById('profileSelect').value;
    if(!name) return;
    const fd = new FormData(); 
    fd.append('action','get_profile'); 
    fd.append('profile_name',name);
    
    fetch('index.php',{method:'POST',body:fd})
        .then(r=>r.json())
        .then(d=>{
            if (!Array.isArray(d)) {
                alert('Błąd wczytywania profilu');
                return;
            }
            toggleAll(false);
            let count = 0;
            document.querySelectorAll('.feature-checkbox').forEach(cb => { 
                if(d.includes(cb.value)) {
                    cb.checked=true;
                    count++;
                }
            });
            alert('Wczytano profil. Zaznaczono cech: ' + count);
        })
        .catch(err => {
            console.error(err);
            alert('Wystąpił błąd podczas komunikacji z serwerem.');
        });
}

function deleteProfileCurrent() {
    const name = document.getElementById('profileSelect').value;
    if(!name) {
        alert('Wybierz profil z listy, aby go usunąć.');
        return;
    }
    if(confirm('Czy na pewno chcesz bezpowrotnie usunąć profil: '+name+'?')) {
        document.getElementById('deleteProfileName').value = name;
        document.getElementById('deleteProfileForm').submit();
    }
}
</script>

</body>
</html>
