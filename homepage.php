<?php
require_once 'config.php';
require_once __DIR__ . '/includes/ui_icons.php';

if (isLoggedIn()) {
    redirect(
        isSecurity() ? 'security/security_dashboard.php'
        : (isMaintenance() ? 'maintenance/maintenance_dashboard.php'
        : (isTreasurer() ? 'treasurer/treasurer_dashboard.php'
        : (isAdmin() ? 'admin/admin_dashboard.php'
        : 'resident/dashboard.php')))
    );
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>The Celandine | Luxury Living in Quezon City</title>
  <meta name="description" content="Experience The Celandine through a premium digital homepage featuring amenities, interiors, property highlights, location, and resident portal access.">
  <meta name="theme-color" content="#0a0f1d">
  <meta property="og:type" content="website">
  <meta property="og:site_name" content="The Celandine">
  <meta property="og:title" content="The Celandine">
  <meta property="og:description" content="Luxury condominium living, premium amenities, and a beautifully designed resident portal in one elegant digital experience.">
  <meta property="og:image" content="assets/homepage/main-building.jpg">
  <meta name="twitter:card" content="summary_large_image">

  <script type="application/ld+json">
  {
    "@context": "https://schema.org",
    "@type": "ApartmentComplex",
    "name": "The Celandine",
    "description": "A residential tower in Quezon City with premium amenities and a digital resident portal for maintenance, billing, bookings, and communication.",
    "address": {
      "@type": "PostalAddress",
      "streetAddress": "A. Bonifacio Avenue",
      "addressLocality": "Quezon City",
      "addressRegion": "Metro Manila",
      "addressCountry": "PH"
    },
    "telephone": "+639552948193",
    "email": "celandinehomes@gmail.com"
  }
  </script>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@500;600;700&family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="preload" as="image" href="assets/homepage/main-building.jpg">
<?php renderPortalUiHead(); ?>
  <link rel="stylesheet" href="<?= htmlspecialchars(portalUiAssetUrl('assets/css/celandine-intro.css'), ENT_QUOTES, 'UTF-8') ?>">
  <script src="<?= htmlspecialchars(portalUiAssetUrl('assets/js/celandine-intro.js'), ENT_QUOTES, 'UTF-8') ?>"></script>
  <link rel="stylesheet" href="homepage.css?v=<?= filemtime(__DIR__ . '/homepage.css') ?>">
</head>
<body class="portal-ui portal-homepage">
  <?php include __DIR__ . '/includes/celandine-intro.php'; ?>
  <a class="skip-link" href="#main-content">Skip to main content</a>
  <header class="home-nav" id="homeNav"><div class="nav-inner"><a href="#main-content" class="brand" aria-label="The Celandine"><?php include 'buildingicon.php'; ?></a><nav class="nav-links" aria-label="Primary navigation"><a href="#overview">Overview</a><a href="#amenities">Amenities</a><a href="#interiors">Interiors</a><a href="#portal">Resident Portal</a><a href="#location">Location</a><a href="#contact">Contact</a></nav><div class="nav-cta"><a class="home-btn home-btn-outline" href="login.php">Log In</a><a class="home-btn home-btn-primary" href="signup.php">Sign Up</a></div><button class="nav-toggle" id="navToggle" type="button" aria-label="Open menu" aria-controls="mobileMenu" aria-expanded="false"><span></span><span></span><span></span></button></div></header>
  <nav class="mobile-menu" id="mobileMenu" aria-label="Mobile navigation" hidden><a href="#overview">Overview</a><a href="#amenities">Amenities</a><a href="#interiors">Interiors</a><a href="#portal">Resident Portal</a><a href="#location">Location</a><a href="#contact">Contact</a><div class="mobile-actions"><a class="home-btn home-btn-outline" href="login.php">Log In</a><a class="home-btn home-btn-primary" href="signup.php">Sign Up</a></div></nav>
  <main id="main-content" tabindex="-1">
    <section class="hero" id="hero" aria-labelledby="heroTitle">
      <div class="hero-bg" aria-hidden="true"></div>
      <div class="wrap hero-inner">
        <div class="hero-text">
          <p class="kicker">LUXURY CONDOMINIUM LIVING · QUEZON CITY</p>
          <h1 id="heroTitle">Where refined<br>city living <em>meets<br>everyday ease.</em></h1>
          <p>Welcome to The Celandine — an elegant vertical community where inviting amenities, beautifully designed interiors, and a seamless resident portal come together.</p>
          <div class="actions"><a class="home-btn home-btn-primary" href="signup.php">Create your resident account <?php echo systemIcon('arrow-right'); ?></a><a class="home-btn home-btn-outline" href="login.php">I already have an account</a></div>
        </div>
        <div class="hero-facts" aria-label="Property highlights">
          <div><strong>Thoughtful spaces</strong><span>Architecture and welcoming<br>common areas</span></div>
          <div><strong>Premium amenities</strong><span>Recreation, wellness<br>and leisure spaces</span></div>
          <div><strong>Quezon City</strong><span>A. Bonifacio Avenue<br>Convenient urban location</span></div>
          <div><strong>Connected living</strong><span>Resident services<br>in one secure portal</span></div>
        </div>
      </div>
    </section>
    <section class="section overview" id="overview" aria-labelledby="overviewTitle">
      <div class="wrap split-overview">
        <div class="section-lead" data-home-reveal><p class="kicker">— OVERVIEW</p><h2 id="overviewTitle">A refined place<br>to call <em>home.</em></h2><p>Contemporary architecture, welcoming common areas, and peaceful garden spaces. A considered setting for everyday life.</p><a class="home-btn home-btn-primary" href="#amenities">Explore the property <?php echo systemIcon('arrow-right'); ?></a></div>
        <div class="overview-cards" data-home-stagger>
          <a class="photo-card" href="#interiors"><img src="assets/homepage/main-building.jpg" alt="The Celandine residential tower" width="1536" height="2048" loading="lazy" decoding="async"><div><strong>Modern Architecture</strong><small>A striking vertical community</small></div></a>
          <a class="photo-card" href="#interiors"><img src="assets/homepage/interior-lobby.jpg" alt="The Celandine lobby lounge" width="1536" height="2048" loading="lazy" decoding="async"><div><strong>Elegant Common Areas</strong><small>Thoughtfully designed shared spaces</small></div></a>
          <a class="photo-card" href="#location"><img src="assets/amenities/amenity-gazebo.jpg" alt="Landscaped garden gazebo" width="1536" height="2048" loading="lazy" decoding="async"><div><strong>Tranquil Surroundings</strong><small>Space to unwind and reconnect</small></div></a>
        </div>
      </div>
    </section>
    <section class="section amenity-section" id="amenities" aria-labelledby="amenitiesTitle">
      <div class="wrap">
        <div class="section-heading" data-home-reveal><div><p class="kicker">— AMENITIES</p><h2 id="amenitiesTitle">Life, beautifully <em>balanced.</em></h2><p>Recreation, wellness, and moments together, just a little closer to home.</p></div><div class="gallery-controls"><button type="button" id="amenityPrev" aria-label="Previous amenities" aria-controls="amenityGallery" hidden><?php echo systemIcon('arrow-right', 'system-icon icon-arrow-left'); ?></button><button type="button" id="amenityNext" aria-label="Next amenities" aria-controls="amenityGallery" hidden><?php echo systemIcon('arrow-right'); ?></button></div></div>
        <div class="gallery-track amenity-track" id="amenityGallery" role="region" aria-label="Amenity photographs; scroll to explore" tabindex="0" data-home-stagger>
<?php
$homepageAmenities = [
    ['amenity-swimming-pool', 'Swimming Pool', 'Recreation', 1400, 826],
    ['amenity-gym.jpg', 'Fitness Gym', 'Wellness', 1536, 2048],
    ['amenity-basketball-court.jpg', 'Basketball Court', 'Active living', 1536, 2048],
    ['amenity-playground.jpg', 'Children’s Playground', 'Family spaces', 1536, 2048],
    ['amenity-grill-garden.jpg', 'Grill Garden', 'Social leisure', 1536, 2048],
    ['amenity-gazebo.jpg', 'Garden Gazebo', 'Quiet retreat', 1536, 2048],
];
foreach ($homepageAmenities as [$photo, $name, $category, $width, $height]):
?>
          <a class="amenity-tile" href="assets/amenities/<?= htmlspecialchars($photo, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer" data-loading="none" data-home-preview data-photo-title="<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>" aria-label="Preview <?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?> photo">
            <img src="assets/amenities/<?= htmlspecialchars($photo, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>" width="<?= $width ?>" height="<?= $height ?>" loading="lazy" decoding="async"><div><strong><?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?></strong><small><?= htmlspecialchars($category, ENT_QUOTES, 'UTF-8') ?></small></div><span class="tile-arrow" aria-hidden="true"><?php echo systemIcon('image'); ?></span>
          </a>
<?php endforeach; ?>
        </div>
      </div>
    </section>
    <div class="section"><div class="wrap dual-panels">
      <section class="interior-block" id="interiors" aria-labelledby="interiorsTitle" data-home-reveal>
        <p class="kicker">— INTERIORS</p><h2 id="interiorsTitle">Warm, elegant interiors with <em>hospitality energy.</em></h2><p>Welcoming spaces that make every arrival and everyday moment feel special.</p>
        <div class="gallery-track interior-track" id="interiorGallery" role="region" aria-label="Interior photographs; scroll to explore" tabindex="0">
<?php
$homepageInteriors = [
    ['interior-function-hall.jpg', 'Function Hall'], ['interior-lobby.jpg', 'Lobby Lounge'],
    ['interior-sky-patio.jpg', 'Sky Patio'], ['interior-reception.jpg', 'Reception'],
    ['interior-hallway.jpg', 'Hallway'], ['interior-elevator.jpg', 'Elevator Area'],
];
foreach ($homepageInteriors as [$photo, $name]):
?>
          <a class="interior-slide" href="assets/homepage/<?= htmlspecialchars($photo, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer" data-loading="none" data-home-preview data-photo-title="<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>" aria-label="Preview <?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?> photo"><img src="assets/homepage/<?= htmlspecialchars($photo, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>" width="1536" height="2048" loading="lazy" decoding="async"><strong><?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?></strong></a>
<?php endforeach; ?>
        </div>
        <div class="gallery-controls"><button type="button" id="galleryPrev" aria-label="Previous interior image" aria-controls="interiorGallery" hidden><?php echo systemIcon('arrow-right', 'system-icon icon-arrow-left'); ?></button><button type="button" id="galleryNext" aria-label="Next interior image" aria-controls="interiorGallery" hidden><?php echo systemIcon('arrow-right'); ?></button></div>
      </section>
      <section class="portal-block" id="portal" aria-labelledby="portalTitle" data-home-reveal>
        <p class="kicker">— RESIDENT PORTAL</p><h2 id="portalTitle">Everyday services,<br><em>all in one place.</em></h2><p>Manage requests, dues, amenity bookings, and property updates through your resident portal.</p>
        <div class="service-grid">
          <article><span><?php echo systemIcon('maintenance'); ?></span><h3>Maintenance Requests</h3><p>File and track concerns</p></article>
          <article><span><?php echo systemIcon('billing'); ?></span><h3>Billing &amp; Dues</h3><p>Review dues and payments</p></article>
          <article><span><?php echo systemIcon('calendar'); ?></span><h3>Amenity Bookings</h3><p>Reserve shared spaces</p></article>
          <article><span><?php echo systemIcon('announcements'); ?></span><h3>Announcements</h3><p>Stay connected with the PMO</p></article>
        </div>
        <div class="actions"><a class="home-btn home-btn-primary" href="signup.php">Start your account <?php echo systemIcon('arrow-right'); ?></a><a class="home-btn home-btn-outline" href="login.php">Open resident login</a></div>
      </section>
    </div></div>
    <section class="section" id="location" aria-labelledby="locationTitle">
      <div class="wrap location-grid">
        <div class="location-copy" data-home-reveal><p class="kicker">— LOCATION</p><h2 id="locationTitle">Find us in <em>Quezon City.</em></h2><p>A convenient urban address, with space to come home to. Situated along A. Bonifacio Avenue, The Celandine brings city connections and a private residential atmosphere together.</p><address>A. Bonifacio Avenue, Quezon City, Philippines</address><div class="actions"><a class="home-btn home-btn-primary" href="https://maps.app.goo.gl/2S6XXVvN17S4wTPKA" target="_blank" rel="noopener noreferrer">Open in Google Maps <?php echo systemIcon('arrow-right'); ?></a><a class="home-btn home-btn-outline" href="#contact">Contact the PMO</a></div></div>
        <div class="map-frame" data-home-reveal><iframe src="https://www.google.com/maps/embed?pb=!1m14!1m8!1m3!1d5458.936513699308!2d120.99965810211934!3d14.653044918359967!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x3397b79bad0c1649%3A0x1813241556e3fd63!2sThe%20Celandine%20by%20DMCI%20Homes!5e0!3m2!1sen!2sph!4v1791484098874!5m2!1sen!2sph" width="600" height="450" style="border:0;" allowfullscreen="" loading="lazy" referrerpolicy="strict-origin-when-cross-origin" title="Map of The Celandine"></iframe></div>
      </div>
    </section>
    <section class="section" id="contact" aria-labelledby="contactTitle">
      <div class="wrap contact-grid"><div data-home-reveal><p class="kicker">— CONTACT</p><h2 id="contactTitle">Here to <em>help.</em></h2><p>Contact the Property Management Office for account assistance, resident concerns, and property-related support.</p></div><div class="contact-details" data-home-reveal><h3>Property Management Office</h3><p><?php echo systemIcon('clock'); ?> Office hours: Mon–Sat, 8:00 AM – 5:00 PM</p><p><?php echo systemIcon('mail'); ?> <a href="mailto:celandinehomes@gmail.com">celandinehomes@gmail.com</a></p><p><?php echo systemIcon('volume'); ?> <a href="tel:+639552948193">0955 294 8193</a></p><a class="text-link" href="resend_verification.php">Resend verification email <?php echo systemIcon('arrow-right'); ?></a></div></div>
    </section>
    <section class="section" id="faq"><div class="wrap"><div class="faq-grid"><article class="faq-card"><h3>Frequently asked questions</h3><div class="faq-list"><details class="faq-item"><summary>Can I sign up even if my unit is not yet assigned in the system?</summary><p>Yes. You can create your account first, and the PMO can review and link your unit after verification.</p></details><details class="faq-item"><summary>What can residents do inside the portal?</summary><p>Residents can check dues, file maintenance requests, view announcements, and manage amenity-related transactions and requests.</p></details><details class="faq-item"><summary>How do I get help with my account?</summary><p>Contact the Property Management Office using the email or phone number above for registration, verification, or unit assignment assistance.</p></details><details class="faq-item"><summary>What if my verification email has not arrived?</summary><p>Check your spam folder or <a href="resend_verification.php">request another verification email</a>.</p></details></div></article><article class="faq-card"><h3>Privacy & terms</h3><div class="faq-list"><details class="faq-item"><summary>Privacy Policy</summary><p class="updated-text">Last updated: <?php echo date('F Y'); ?></p><div class="legal-inline"><h4>Information we collect</h4><p>We collect account registration details such as name, username, email address, contact number, and password hash, along with data created while using the portal such as billing records, maintenance requests, booking history, and resident messages.</p><h4>How the information is used</h4><ul><li>To create and verify resident accounts</li><li>To assign residents to actual units</li><li>To process maintenance, bookings, billing, and operational requests</li><li>To support PMO communication and administration</li></ul><h4>Data rights</h4><p>Residents may request access to their information, correction of inaccurate data, and other protections consistent with the Data Privacy Act of 2012 (RA 10173).</p></div></details><details class="faq-item"><summary>Terms of Service</summary><p class="updated-text">Last updated: <?php echo date('F Y'); ?></p><div class="legal-inline"><h4>Using the portal</h4><p>Residents and authorized users are expected to provide accurate information and use the system responsibly for maintenance, billing, amenity booking, and communication.</p><h4>Account approval</h4><p>Full access to the portal is granted after PMO review and unit assignment when required.</p><h4>Operational precedence</h4><p>Official notices, PMO-issued statements, and management records take precedence in case of discrepancies between printed documents and portal content.</p></div></details></div></article></div></div></section>
  </main>
  <footer class="footer"><div class="wrap"><div class="footer-inner"><a class="footer-brand" href="#main-content" aria-label="The Celandine homepage"><?php include 'buildingicon.php'; ?></a><nav aria-label="Footer navigation"><a href="#overview">Overview</a><a href="#amenities">Amenities</a><a href="#interiors">Interiors</a><a href="#portal">Resident Portal</a><a href="#location">Location</a><a href="#contact">Contact</a></nav><div class="footer-access"><a href="login.php">Log In</a><a href="signup.php">Sign Up</a><a href="resend_verification.php">Resend Verification</a></div></div><div class="footer-bottom"><a href="#faq">Privacy &amp; Terms</a><span>© <?php echo date('Y'); ?> The Celandine. All rights reserved.</span></div></div></footer>
  <button id="backToTop" class="back-to-top" type="button" aria-label="Back to top" hidden><?php echo systemIcon('arrow-right', 'system-icon icon-arrow-up'); ?></button>
  <dialog class="home-lightbox" id="homeLightbox" aria-labelledby="homePhotoTitle" aria-describedby="homePhotoStatus">
    <div class="lightbox-head"><h2 id="homePhotoTitle">Property photograph</h2><button class="lightbox-close" type="button" aria-label="Close photo preview" autofocus><?php echo systemIcon('close'); ?></button></div>
    <div class="lightbox-photo"><img id="homePhoto" alt=""><p id="homePhotoStatus" role="status"></p></div>
    <div class="lightbox-controls"><button type="button" id="photoPrev" aria-label="Previous photo"><?php echo systemIcon('arrow-right', 'system-icon icon-arrow-left'); ?></button><span id="homePhotoCount" aria-live="polite"></span><button type="button" id="photoNext" aria-label="Next photo"><?php echo systemIcon('arrow-right'); ?></button></div>
  </dialog>
  <script defer src="js/homepage.js?v=<?= filemtime(__DIR__ . '/js/homepage.js') ?>"></script>
</body>
</html>
