<?php
/** Shared information and local photographs for all amenity views. */
function amenityCatalog(): array {
    return [
        'Swimming Pool'=>['slug'=>'swimming-pool','description'=>'An outdoor swimming pool for recreation and private group visits.','hours'=>'6:00 AM – 9:00 PM','location'=>'3rd floor pool area','capacity'=>20,'booking'=>true,'price'=>'₱300 / hour','rules'=>'Exclusive reservation for 1–3 consecutive hours. Maximum 20 guests. Admin approval is required.'],
        'Function Hall'=>['slug'=>'function-hall','description'=>'A gathering space for resident celebrations, meetings and events.','hours'=>'Arrival from 6:00 AM to 8:00 PM; full-day reservation','location'=>'Function Hall area; confirm access with PMO','capacity'=>100,'booking'=>true,'price'=>'₱3,000 / day','rules'=>'Exclusive full-day reservation for up to 100 guests. Select your arrival time. Admin approval is required.'],
        'Basketball Court'=>['slug'=>'basketball-court','description'=>'An outdoor court for basketball and recreational exercise.','hours'=>'Contact PMO for the current operating schedule','location'=>'Outdoor recreation area','booking'=>false],
        'Playground'=>['slug'=>'playground','description'=>'An outdoor play area for children and families. Adult supervision is recommended.','hours'=>'Contact PMO for the current operating schedule','location'=>'Outdoor recreation area','booking'=>false],
        'Fitness Gym'=>['slug'=>'gym','description'=>'An indoor exercise space with fitness equipment for resident workouts.','hours'=>'Contact PMO for the current operating schedule','location'=>'Fitness gym; confirm floor and access with PMO','booking'=>false],
        'Grill Garden'=>['slug'=>'grill-garden','description'=>'An open-air garden area for relaxing and spending time with neighbors.','hours'=>'Contact PMO for the current operating schedule','location'=>'Garden dining area','booking'=>false],
        'Gazebo'=>['slug'=>'gazebo','description'=>'A shaded garden pavilion for quiet breaks and conversation.','hours'=>'Contact PMO for the current operating schedule','location'=>'Outdoor garden area','booking'=>false],
        'Sky Lounge'=>['slug'=>'sky-lounge','description'=>'An indoor lounge space for relaxation and casual conversation.','hours'=>'Contact PMO for the current operating schedule','location'=>'Sky Lounge; confirm floor and access with PMO','booking'=>false],
        'Landscape Garden'=>['slug'=>'landscape-garden','description'=>'A landscaped garden with greenery and peaceful outdoor spaces.','hours'=>'Contact PMO for the current operating schedule','location'=>'Landscaped outdoor area','booking'=>false],
    ];
}
function amenityImages(string $slug): array {
    if (!in_array($slug,array_column(amenityCatalog(),'slug'),true)) return [];
    $images=[];
    foreach (glob(__DIR__.'/../assets/amenities/amenity-'.$slug.'*') ?: [] as $path) {
        if (!is_file($path) || !preg_match('/^amenity-'.preg_quote($slug,'/').'(?:$|[._-])/D',basename($path))) continue;
        $size=@getimagesize($path);
        if (!$size || !in_array($size['mime'],['image/jpeg','image/png','image/webp','image/avif','image/gif'],true)) continue;
        $images[]=buildUrl('assets/amenities/'.rawurlencode(basename($path)));
    }
    return $images;
}
function renderAmenityGallery(string $name,bool $lazy=true): void {
    $entry=amenityCatalog()[$name] ?? null; if (!$entry) return;
    $images=amenityImages($entry['slug']);
    $escape=static fn(string $value):string=>htmlspecialchars($value,ENT_QUOTES,'UTF-8');
    ?>
    <div class="amenity-gallery" data-amenity-gallery data-name="<?= $escape($name) ?>" data-images="<?= $escape(json_encode($images,JSON_UNESCAPED_SLASHES)) ?>">
        <button type="button" class="amenity-image-open" data-open-image aria-label="View larger photo of <?= $escape($name) ?>" <?= !$images?'hidden':'' ?>>
            <?php if($images): ?><img src="<?= $escape($images[0]) ?>" alt="<?= $escape($name) ?> at The Celandine Homes" loading="<?= $lazy?'lazy':'eager' ?>" decoding="async" width="1400" height="900"><span class="amenity-image-hint">View photo ↗</span><?php endif; ?>
        </button>
        <div class="amenity-image-fallback" <?= $images?'hidden':'' ?>><strong><?= $escape($name) ?></strong><span>Photo unavailable</span></div>
        <?php if(count($images)>1): ?><div class="amenity-gallery-nav"><button type="button" data-gallery-step="-1" aria-label="Previous <?= $escape($name) ?> photo">‹</button><span data-gallery-count>1 / <?= count($images) ?></span><button type="button" data-gallery-step="1" aria-label="Next <?= $escape($name) ?> photo">›</button></div><?php endif; ?>
    </div>
    <?php
}
