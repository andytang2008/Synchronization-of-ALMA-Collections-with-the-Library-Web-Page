<?php
$apiKey = 'Please put your institution API key here';
$collectionId = '81112210130002904';   //Pleaes replace this collectionId with the one used in your library
$baseUrl = "https://api-na.hosted.exlibrisgroup.com/almaws/v1/bibs/";

// Pagination setup
$limit = 10; // items per page
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $limit;

// Step 1: get collection items
$collectionUrl = "{$baseUrl}collections/{$collectionId}/bibs?offset={$offset}&limit={$limit}&apikey={$apiKey}";
$ch = curl_init($collectionUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ["Accept: application/xml"],
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => 0
]);
$response = curl_exec($ch);
curl_close($ch);

// Parse collection XML
$xml = simplexml_load_string($response);
$totalRecords = (int)$xml['total_record_count'];
$totalPages = ceil($totalRecords / $limit);

$mmsIds = [];
$titles = [];
$pubDates = [];
foreach ($xml->bib as $bib) {
    $mmsIds[] = (string)$bib->mms_id;
    $titles[(string)$bib->mms_id] = (string)$bib->title;
    $pubDates[(string)$bib->mms_id] = isset($bib->date_of_publication) ? (string)$bib->date_of_publication : '';
}

// Step 2: fetch full records in parallel to get 956 links
$multiHandle = curl_multi_init();
$curlHandles = [];
foreach ($mmsIds as $id) {
    $url = "{$baseUrl}{$id}?view=full&expand=None&apikey={$apiKey}";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ["Accept: application/xml"],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0
    ]);
    curl_multi_add_handle($multiHandle, $ch);
    $curlHandles[$id] = $ch;
}

$running = null;
do {
    curl_multi_exec($multiHandle, $running);
    curl_multi_select($multiHandle);
} while ($running > 0);

// Step 3: collect 956 'u' links
$thumbnails = [];
foreach ($curlHandles as $id => $ch) {
    $xmlFull = simplexml_load_string(curl_multi_getcontent($ch));
    if ($xmlFull && isset($xmlFull->record->datafield)) {
        foreach ($xmlFull->record->datafield as $df) {
            if ((string)$df['tag'] === '956') {
                foreach ($df->subfield as $sf) {
                    if ((string)$sf['code'] === 'u') {
                        $thumbnails[$id] = (string)$sf;
                    }
                }
            }
        }
    }
    curl_multi_remove_handle($multiHandle, $ch);
}
curl_multi_close($multiHandle);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Alma Collection Items</title>
<!-- Bootstrap 5 CSS -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
    .title-link {
        color: blue;
        text-decoration: underline;
        font-weight: bold;
    }
    .title-link:hover {
        color: darkblue;
    }
    .card img {
        max-height: 200px;
        object-fit: contain;
    }
</style>
</head>
<body>
<div class="container my-4">
    <h2 class="mb-4">Alma Collection Items</h2>

    <div class="row g-4">
        <?php foreach ($mmsIds as $id): ?>
        <div class="col-12 col-sm-6 col-md-4 col-lg-3">
            <div class="card h-100 shadow-sm">
                <?php if (isset($thumbnails[$id])): ?>
                    <img src="<?= htmlspecialchars($thumbnails[$id]) ?>" class="card-img-top" alt="Thumbnail">
                <?php else: ?>
                    <img src="https://via.placeholder.com/150x200?text=No+Image" class="card-img-top" alt="No Thumbnail">
                <?php endif; ?>
                <div class="card-body">
                    <h6 class="card-title">
                        <a class="title-link" href="https://csu-chico.primo.exlibrisgroup.com/discovery/fulldisplay?docid=alma<?= urlencode($id) ?>&vid=01CALS_CHI:01CALS_CHI&lang=en" target="_blank">
                            <?= htmlspecialchars($titles[$id]) ?>
                        </a>
                    </h6>
                    <p class="card-text text-muted mb-0">MMS ID: <?= htmlspecialchars($id) ?></p>
                    <p class="card-text"><small>Published: <?= htmlspecialchars($pubDates[$id]) ?></small></p>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Bootstrap Pagination -->
    <nav aria-label="Page navigation" class="mt-4">
        <ul class="pagination justify-content-center">
        <?php for ($p = 1; $p <= $totalPages; $p++): ?>
            <?php if ($p == $page): ?>
                <li class="page-item active" aria-current="page">
                    <span class="page-link"><?= $p ?></span>
                </li>
            <?php else: ?>
                <li class="page-item"><a class="page-link" href="?page=<?= $p ?>"><?= $p ?></a></li>
            <?php endif; ?>
        <?php endfor; ?>
        </ul>
    </nav>
</div>

<!-- Bootstrap 5 JS Bundle -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
