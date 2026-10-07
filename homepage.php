<?php
require_once 'config.php';

if (isLoggedIn()) {
    redirect(isSecurity() ? 'security/security_dashboard.php' : (isMaintenance() ? 'maintenance/maintenance_dashboard.php' : (isTreasurer() ? 'treasurer/treasurer_dashboard.php' : (isAdmin() ? 'admin/admin_dashboard.php' : 'resident/dashboard.php'))));
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>The Celandine Residences</title>
<meta name="description" content="The Celandine Residences — a residential tower on A. Bonifacio Avenue, Quezon City. File maintenance requests, pay dues, book amenities, and stay connected with the PMO in one resident portal.">
<meta name="theme-color" content="#0a0f1d">
<meta property="og:type" content="website">
<meta property="og:site_name" content="The Celandine Residences">
<meta property="og:title" content="The Celandine Residences">
<meta property="og:description" content="One portal for everything that keeps The Celandine running — maintenance, dues, bookings, and the people who manage it.">
<meta property="og:image" content="IMAGES/images.jpg">
<meta name="twitter:card" content="summary_large_image">

<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "ApartmentComplex",
  "name": "The Celandine Residences",
  "description": "A residential tower on A. Bonifacio Avenue, Quezon City, managed through an online resident portal for maintenance, billing, and amenity bookings.",
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
<link href="https://fonts.googleapis.com/css2?family=Fraunces:ital,opsz,wght@0,9..144,400;0,9..144,500;0,9..144,600;1,9..144,500&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">  
<link rel="stylesheet" href="homepage.css?v=15">
<script src="js/page-transition.js?v=2" defer></script>
</head>
<body class="home">
<div class="page-transition" aria-hidden="true"></div>
<a class="skip-link" href="#main-content">Skip to main content</a>

<header class="nav">
  <div class="wrap">
    <div class="nav-start">
      <button class="nav-toggle" id="navToggle" type="button" aria-label="Toggle menu" aria-expanded="false" aria-controls="mobileMenu">
        <span></span><span></span><span></span>
      </button>
      <a class="nav-brand" href="#main-content">
        <?php include 'buildingicon.php'; ?>
        The Celandine Residences
      </a>
    </div>
    <nav class="nav-links">
      <a class="section-link" href="#about">About Us</a>
      <a class="section-link" href="#units">Units</a>
      <a class="section-link" href="#getting-started">Getting Started</a>
      <a class="section-link" href="#community">Community</a>
      <a class="section-link" href="#contact">Contact</a>
    </nav>
    <div class="nav-cta">
      <a class="btn btn-ghost" href="login.php">Log In</a>
      <a class="btn btn-gold" href="signup.php">Sign Up</a>
    </div>
  </div>
  <nav class="mobile-menu" id="mobileMenu">
    <a class="section-link" href="#about">About Us</a>
    <a class="section-link" href="#units">Units</a>
    <a class="section-link" href="#getting-started">Getting Started</a>
    <a class="section-link" href="#community">Community</a>
    <a class="section-link" href="#contact">Contact</a>
  </nav>
</header>

<section class="hero" id="main-content">
  <div class="wrap">
    <div>
      <div class="hero-eyebrow">Quezon City</div>
      <h1>Home, managed with care.</h1>
      <p class="lede">One portal for everything that keeps The Celandine running smoothly — maintenance, amenities, billing, and the people who look after it.</p>
      <div class="hero-ctas">
        <a class="btn btn-gold" href="signup.php">Create your resident account</a>
        <a class="btn btn-ghost" href="login.php">I already have an account</a>
      </div>
      <div class="hero-stats">
        <div><span class="n">24/7</span><span class="l">Security on site</span></div>
        <div><span class="n">PMO</span><span class="l">Managed units</span></div>
        <div><span class="n">Online</span><span class="l">Maintenance requests</span></div>
      </div>
    </div>
    <div class="hero-photo">
      <img src="IMAGES/home.jpg" alt="The Celandine building exterior">
      <div class="hero-photo-tag">The Celandine · Quezon City</div>
    </div>
  </div>
</section>

<section class="about" id="about">
  <div class="wrap about-grid">
    <div class="about-photo reveal">
      <img src="IMAGES/TheEntranceGate.webp" alt="The Celandine entrance gate" loading="lazy" decoding="async">
    </div>
    <div class="reveal">
      <div class="hero-eyebrow">About Us</div>
      <h2>A high-rise built for everyday living.</h2>
      <p class="lede">The Celandine stands along A. Bonifacio Avenue in Quezon City — a residential tower with a modern contemporary theme, where landscaped grounds meet the pace of the city right outside. This portal was built around the way residents actually live here: filing a maintenance report, checking a due, or reserving the court, without waiting on a phone call or a group chat.</p>
      <div class="feature-grid">
        <div class="feature-card">
          <span class="feature-icon">⌂</span>
          <div><h4>On-site PMO</h4><p>Property Management Office requests are coordinated directly through this portal.</p></div>
        </div>
        <div class="feature-card">
          <span class="feature-icon">✓</span>
          <div><h4>Verified access</h4><p>Resident accounts are reviewed and assigned a unit by the admin office before use.</p></div>
        </div>
        <div class="feature-card">
          <span class="feature-icon">▤</span>
          <div><h4>One record</h4><p>Dues, bookings, and requests in one place — instead of scattered chats and paper forms.</p></div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- Unit Types Overview -->
<section class="units-section" id="units">
  <div class="wrap">
    <div class="howto-head reveal">
      <div class="hero-eyebrow">Unit Offerings</div>
      <h2>Designed for your lifestyle.</h2>
      <p>Explore the efficient space layouts built for comfort and modern urban living at The Celandine.</p>
    </div>
    <div class="units-grid reveal-stagger">
      <div class="unit-card">
        <span class="unit-badge">S</span>
        <h3>Studio Unit</h3>
        <p>Ideal for students, young professionals, or starting individuals looking for a cozy and accessible space.</p>
        <span class="unit-tag">Optimized Space &bull; Modern Finish</span>
      </div>
      <div class="unit-card">
        <span class="unit-badge">1BR</span>
        <h3>1-Bedroom Unit</h3>
        <p>Features a spacious living area and bedroom layout designed for maximum ventilation and natural lighting.</p>
        <span class="unit-tag">Comfortable &bull; Functional Layout</span>
      </div>
      <div class="unit-card">
        <span class="unit-badge">2BR</span>
        <h3>2-Bedroom Unit</h3>
        <p>Perfect for growing families who want a secure, resort-inspired community right inside Quezon City.</p>
        <span class="unit-tag">Family-Ready &bull; Ample Space</span>
      </div>
    </div>
  </div>
</section>

<section class="howto" id="getting-started">
  <div class="wrap">
    <div class="howto-head">
      <h2>Getting started is simple.</h2>
      <p>New to The Celandine? Here's what happens after you sign up.</p>
    </div>
    <div class="howto-steps">
      <div class="howto-step">
        <div class="howto-n">01</div>
        <h3>Create your account</h3>
        <p>Sign up with your name, contact details, and email — no unit number needed yet.</p>
      </div>
      <div class="howto-step">
        <div class="howto-n">02</div>
        <h3>PMO reviews &amp; assigns your unit</h3>
        <p>The admin office verifies your details and links your account to your actual unit.</p>
      </div>
      <div class="howto-step">
        <div class="howto-n">03</div>
        <h3>Log in to your portal</h3>
        <p>Pay dues, book amenities, file maintenance requests, and stay updated — all in one place.</p>
      </div>
    </div>
    <a class="btn btn-gold" href="signup.php">Create your resident account</a>
  </div>
</section>

<section class="community" id="community">
  <div class="wrap">
    <div class="reveal">
      <h2>Living well together.</h2>
      <p>A few of the house rules that keep The Celandine comfortable for everyone. The full set is in your resident handbook.</p>
      <a class="btn btn-ghost handbook-cta" href="signup.php">Sign up to view the full handbook</a>
    </div>
    <div class="reveal-stagger">
      <div class="rule">
        <span>Building rules</span>
        <p>Residents follow the Condominium Corporation's rules on use, occupancy, and sanitation, which the Board may update from time to time.</p>
      </div>
      <div class="rule">
        <span>Accountability</span>
        <p>Unit owners are responsible for violations by household members, tenants, or guests, and for any related penalties if left unaddressed.</p>
      </div>
      <div class="rule">
        <span>Liens on a unit</span>
        <p>Owners must notify the Condominium Corporation in writing within five days of any lien or legal matter affecting their unit's title.</p>
      </div>
      <div class="rule">
        <span>Selling a unit</span>
        <p>A Certificate of Management from the PMO is required before a sale, with the notarized Deed of Sale filed with the PMO afterward.</p>
      </div>
    </div>
  </div>
</section>

<section class="faq" id="faq">
  <div class="wrap faq-columns">
    <div class="faq-column">
      <div class="faq-head reveal">
        <h2>Before you sign up.</h2>
        <p>A few questions new residents usually ask.</p>
      </div>
      <div class="faq-list reveal-stagger">
        <details class="faq-item">
          <summary>Can I sign up with my unit number?</summary>
          <p>Yes, you can input your unit number during sign-up, and the PMO will check and verify if you are the actual owner or resident of that unit.</p>
        </details>
        <details class="faq-item">
          <summary>How does unit verification work?</summary>
          <p>The admin office checks your details against official records, then assigns your account to your unit. You'll be able to log in and use the portal once that's done.</p>
        </details>
        <details class="faq-item">
          <summary>I didn't get a verification email. What now?</summary>
          <p>Check your spam folder first. If it's still not there, use Resend Verification Email on the login page to get a new one sent.</p>
        </details>
        <details class="faq-item">
          <summary>What can I do once I'm in?</summary>
          <p>Check and pay your dues, file maintenance requests, book amenities, and stay updated on announcements — all from one account.</p>
        </details>
      </div>
    </div>

    <div class="faq-column" id="legal-info">
      <div class="faq-head reveal">
        <h2>Legal</h2>
        <p>Privacy practices and terms for using this portal.</p>
      </div>
      <div class="faq-list reveal-stagger">
        <details class="faq-item" id="privacy-policy">
          <summary>Privacy Policy</summary>
          <div class="legal-inline">
            <p class="updated">Last updated: <?php echo date('F Y'); ?></p>
            <p>This Privacy Policy explains how The Celandine Residences Property Management Office (PMO) collects, uses, and protects the personal information of residents who register for and use this resident portal.</p>

            <h3>1. Information We Collect</h3>
            <p>When you create an account, we collect your full name, username, email address, contact number, and password (stored as a one-way hash, never in plain text). Once your account is reviewed and approved, your unit number is linked to your account by the PMO. Additional information — such as payment records, maintenance requests, booking history, and messages — is collected as you use the portal.</p>

            <h3>2. How We Use Your Information</h3>
            <ul>
              <li>To create, verify, and approve your resident account</li>
              <li>To assign and confirm your unit</li>
              <li>To process and record billing, dues, and payments</li>
              <li>To manage amenity bookings and maintenance requests</li>
              <li>To coordinate with security on visitor and access logs</li>
              <li>To send announcements and respond to your messages</li>
            </ul>

            <h3>3. Data Storage &amp; Security</h3>
            <p>Your password is never stored in readable form. Access to resident data within the system is limited to authorized PMO and administrative staff. We apply reasonable technical safeguards (such as session controls and account lockouts after repeated failed logins) to help protect your account from unauthorized access.</p>

            <h3>4. Data Sharing</h3>
            <p>We do not sell or rent your personal information. Your data may only be shared with the Condominium Corporation, PMO staff, or relevant authorities when necessary for building operations, security, billing, or as required by law.</p>

            <h3>5. Your Rights Under the Data Privacy Act of 2012 (RA 10173)</h3>
            <p>As a data subject under Philippine law, you have the right to:</p>
            <ul>
              <li>Be informed that your personal data is being collected and processed</li>
              <li>Access your personal data held by the PMO</li>
              <li>Request correction of inaccurate or outdated information</li>
              <li>Object to the processing of your data, subject to legal and contractual limits</li>
              <li>Request erasure or blocking of your data under certain conditions</li>
              <li>File a complaint with the National Privacy Commission (NPC)</li>
            </ul>

            <h3>6. Data Retention</h3>
            <p>We retain resident account and transaction data for as long as you remain a resident, and for a reasonable period afterward as needed for recordkeeping, billing reconciliation, or legal compliance.</p>

            <h3>7. Changes to This Policy</h3>
            <p>This Privacy Policy may be updated from time to time. Significant changes will be communicated through an announcement on the resident portal.</p>

            <h3>8. Contact Us</h3>
            <p>For questions, concerns, or requests regarding your personal data, reach the PMO at <a href="mailto:celandinehomes@gmail.com">celandinehomes@gmail.com</a> or <a href="tel:+639552948193">0955 294 8193</a>.</p>
          </div>
        </details>

        <details class="faq-item" id="terms">
          <summary>Terms of Service</summary>
          <div class="legal-inline">
            <p class="updated">Last updated: <?php echo date('F Y'); ?></p>
            <p>These Terms of Service govern your use of the CondoSystem resident portal operated by The Celandine Residences Property Management Office (PMO). By creating an account, you agree to these terms.</p>

            <h3>1. Acceptance of Terms</h3>
            <p>By registering for or using this portal, you confirm that you are a resident, unit owner, or authorized representative of a unit at The Celandine, and that you agree to be bound by these Terms and the building's house rules.</p>

            <h3>2. Account Registration &amp; Approval</h3>
            <p>You must provide accurate and complete information when signing up. New accounts are reviewed by the PMO, which verifies your details and assigns your account to your unit. Full access to the portal is only granted once your account has been approved.</p>

            <h3>3. Account Responsibilities</h3>
            <ul>
              <li>Keep your password confidential and do not share your account with others</li>
              <li>You are responsible for activity carried out under your account</li>
              <li>Notify the PMO immediately if you suspect unauthorized access to your account</li>
            </ul>

            <h3>4. Acceptable Use</h3>
            <p>You agree not to submit false maintenance reports, misuse the messaging or booking features, or use the portal in any way that disrupts other residents or building operations. Use of the portal is also subject to the Condominium Corporation's rules on conduct, occupancy, and sanitation.</p>

            <h3>5. Billing &amp; Payments</h3>
            <p>Dues and payment records shown in the portal are provided for your convenience. Official billing statements and receipts issued by the PMO take precedence in case of any discrepancy.</p>

            <h3>6. Amenity Bookings</h3>
            <p>Amenity reservations are subject to availability and PMO approval. The PMO reserves the right to cancel or reschedule a booking for maintenance, safety, or operational reasons.</p>

            <h3>7. Violations &amp; Penalties</h3>
            <p>Violations of house rules reported or recorded through the portal are handled according to the Condominium Corporation's policies, and may result in penalties as determined by the PMO or the Board.</p>

            <h3>8. Limitation of Liability</h3>
            <p>This portal is provided as a management tool for the convenience of residents. The PMO is not liable for service interruptions, data loss, or damages arising from the use or inability to use the portal, except as required by law.</p>

            <h3>9. Termination</h3>
            <p>The PMO may suspend or deactivate an account for violations of these Terms, the house rules, or for fraudulent or harmful use of the portal.</p>

            <h3>10. Governing Law</h3>
            <p>These Terms are governed by the laws of the Republic of the Philippines.</p>

            <h3>11. Changes to These Terms</h3>
            <p>These Terms may be updated from time to time. Continued use of the portal after changes take effect constitutes acceptance of the revised Terms.</p>

            <h3>12. Contact Us</h3>
            <p>Questions about these Terms can be directed to the PMO at <a href="mailto:celandinehomes@gmail.com">celandinehomes@gmail.com</a> or <a href="tel:+639552948193">0955 294 8193</a>.</p>
          </div>
        </details>
      </div>
    </div>
  </div>
</section>

<section class="final-cta">
  <div class="wrap">
    <h2>Ready when you are.</h2>
    <p>Whether you're already living here or just moving in, your account is how you'll reach the PMO from now on.</p>
    <div class="final-cta-ctas">
      <a class="btn btn-gold" href="signup.php">Create your resident account</a>
      <a class="btn btn-ghost" href="login.php">Log in to your account</a>
    </div>
  </div>
</section>

<footer class="home-footer" id="contact">
  <div class="wrap">
    <div>
      <div class="nav-brand footer-brand"><?php include 'buildingicon.php'; ?> The Celandine Residences</div>
      <p>A. Bonifacio Avenue, Quezon City, Philippines</p>
      <div class="footer-map">
        <a href="https://maps.google.com/?q=The+Celandine+By+DMCI+Homes+A.+Bonifacio+Avenue+Quezon+City" target="_blank" rel="noopener noreferrer" class="map-link-overlay" title="Open in Google Maps"></a>
        <iframe src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3860.4075196583997!2d120.99971507571343!3d14.643680075440788!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x3397b713833f01c9%3A0x6b840e6c702bc0f!2sThe%20Celandine%20By%20DMCI%20Homes!3s!2f0!3f0!3m2!1i1024!2i768!4f13.1" loading="lazy" referrerpolicy="no-referrer-when-downgrade" title="Map of The Celandine Residences"></iframe>
      </div>
    </div>
    <div>
      <h4>Property Management Office</h4>
      <ul>
        <li>Office hours: Mon–Sat, 8AM–5PM</li>
        <li>Email: <a href="mailto:celandinehomes@gmail.com">celandinehomes@gmail.com</a></li>
        <li>Contact: <a href="tel:+639552948193">0955 294 8193</a></li>
      </ul>
    </div>
    <div>
      <h4>Portal</h4>
      <ul>
        <li><a href="login.php">Log In</a></li>
        <li><a href="signup.php">Sign Up</a></li>
        <li><a href="resend_verification.php">Resend Verification Email</a></li>
      </ul>
    </div>
  </div>
  <div class="wrap bottom">
    <p class="footer-legal"><a href="#privacy-policy">Privacy Policy</a> &middot; <a href="#terms">Terms of Service</a></p>
    © <?php echo date('Y'); ?> The Celandine Residences. All rights reserved.
  </div>
</footer>

<button id="backToTop" class="back-to-top" type="button" aria-label="Back to top">↑</button>

<script>
  // Auto-open and scroll to the Privacy Policy / Terms of Service details
  // when landing on #privacy-policy or #terms (e.g. from the footer link,
  // or a bookmarked/shared URL with that hash).
  (function () {
    function openFromHash() {
      const id = window.location.hash.slice(1);
      if (!id) return;
      const el = document.getElementById(id);
      if (el && el.tagName === 'DETAILS') {
        el.open = true;
        el.scrollIntoView({ behavior: 'smooth', block: 'start' });
      }
    }
    openFromHash();
    window.addEventListener('hashchange', openFromHash);
  })();

  // Mobile hamburger menu
  (function () {
    const toggle = document.getElementById('navToggle');
    const menu = document.getElementById('mobileMenu');
    function closeMenu() {
      menu.classList.remove('open');
      toggle.classList.remove('open');
      toggle.setAttribute('aria-expanded', 'false');
    }
    toggle.addEventListener('click', function () {
      const isOpen = menu.classList.toggle('open');
      toggle.classList.toggle('open', isOpen);
      toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    });
    menu.querySelectorAll('a').forEach(function (link) {
      link.addEventListener('click', closeMenu);
    });
    window.addEventListener('resize', function () {
      if (window.innerWidth > 860) closeMenu();
    });
  })();

  // Back to top button — appears once the visitor has scrolled down a bit
  (function () {
    const btn = document.getElementById('backToTop');
    const prefersReduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    window.addEventListener('scroll', function () {
      btn.classList.toggle('visible', window.scrollY > 600);
    }, { passive: true });
    btn.addEventListener('click', function () {
      window.scrollTo({ top: 0, behavior: prefersReduced ? 'auto' : 'smooth' });
    });
  })();

  // Fast anchor scroll — native scroll-behavior:smooth takes longer for
  // farther sections (e.g. Contact at the bottom crawls past everything in
  // between). This overrides it with a short, fixed-duration animation so
  // every in-page link feels equally quick regardless of distance. It does
  // NOT preventDefault, so the browser still updates the URL hash normally
  // (needed for the privacy-policy/terms auto-open logic above) — this just
  // takes over the visual scrolling on top of that.
  (function () {
    const DURATION = 300;
    const prefersReduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (prefersReduced) return;

    function animateScrollTo(targetY) {
      const startY = window.scrollY;
      const diff = targetY - startY;
      if (Math.abs(diff) < 2) return;
      const startTime = performance.now();
      function step(now) {
        const t = Math.min((now - startTime) / DURATION, 1);
        const eased = 1 - Math.pow(1 - t, 3);
        window.scrollTo(0, startY + diff * eased);
        if (t < 1) requestAnimationFrame(step);
      }
      requestAnimationFrame(step);
    }

    document.querySelectorAll('a[href^="#"]').forEach(function (link) {
      link.addEventListener('click', function () {
        const id = link.getAttribute('href').slice(1);
        const target = document.getElementById(id);
        if (!target) return;
        requestAnimationFrame(function () {
          animateScrollTo(target.getBoundingClientRect().top + window.scrollY);
        });
      });
    });
  })();

  // Scrollspy — underlines whichever nav link matches the section currently
  // in view, so visitors can see where they are on the page as they scroll.
  (function () {
    // 'main-content' is the hero — it has no matching nav link, so landing
    // on it (e.g. after clicking the logo to scroll back to top) clears
    // whichever link was last underlined instead of leaving it stuck.
    const sectionIds = ['main-content', 'about', 'units', 'getting-started', 'community', 'contact'];
    const sections = sectionIds.map(function (id) { return document.getElementById(id); }).filter(Boolean);
    const navLinks = document.querySelectorAll('.nav-links a.section-link, .mobile-menu a.section-link');
    if (!sections.length || !navLinks.length || !('IntersectionObserver' in window)) return;

    function setActive(id) {
      navLinks.forEach(function (link) {
        link.classList.toggle('active', link.getAttribute('href') === '#' + id);
      });
    }

    const spy = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) setActive(entry.target.id);
      });
    }, { rootMargin: '-45% 0px -50% 0px', threshold: 0 });

    sections.forEach(function (section) { spy.observe(section); });
  })();

  // Scroll-reveal — fades sections in once as they enter the viewport.
  // Falls back to simply showing everything if IntersectionObserver isn't
  // available, or if the visitor has asked for reduced motion.
  (function () {
    const targets = document.querySelectorAll('.reveal, .reveal-stagger');
    const prefersReduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (!('IntersectionObserver' in window) || prefersReduced) {
      targets.forEach(function (el) { el.classList.add('is-visible'); });
      return;
    }
    const observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          entry.target.classList.add('is-visible');
          observer.unobserve(entry.target);
        }
      });
    }, { threshold: 0.15, rootMargin: '0px 0px -40px 0px' });
    targets.forEach(function (el) { observer.observe(el); });
  })();
</script>

</body>
</html>