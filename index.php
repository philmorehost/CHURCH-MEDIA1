<?php
// Redirect to installer if the config file doesn't exist.
if (!file_exists('config/db_connect.php')) {
    header('Location: installer/');
    exit;
}

require_once 'config/db_connect.php';
require_once 'includes/public_header.php';
require_once 'includes/helpers.php';

// Hero Section Data
$hero_type = get_setting('hero_type');
$hero_image_url = get_setting('hero_image_url');
$hero_video_url = get_setting('hero_video_url');

// Upcoming Event Data
$upcoming_event_id = get_setting('upcoming_event_id');
if ($upcoming_event_id) {
    $stmt = $pdo->prepare("SELECT * FROM events WHERE id = ?");
    $stmt->execute([$upcoming_event_id]);
    $event = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Latest Sermon Data (Automated)
$stmt = $pdo->query("SELECT * FROM media ORDER BY publication_date DESC LIMIT 1");
$sermon = $stmt->fetch(PDO::FETCH_ASSOC);

// Announcements
$announcements = [];
try {
    $announcements_stmt = $pdo->query("
        SELECT a.*, u.email as author_email
        FROM announcements a
        LEFT JOIN admin_users u ON a.author_id = u.id
        ORDER BY a.created_at DESC
        LIMIT 3
    ");
    $announcements = $announcements_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Gracefully handle error if the schema is not up to date
    // The admin dashboard will show a warning to the admin.
}
?>

<!-- Hero Section -->
<header class="hero-section" style="<?php echo ($hero_type == 'image' && $hero_image_url) ? 'background-image: url(' . htmlspecialchars($hero_image_url) . '); background-size: contain; background-position: center; background-repeat: no-repeat;' : ''; ?>">
    <?php if ($hero_type == 'video' && $hero_video_url): ?>
        <video playsinline="playsinline" autoplay="autoplay" muted="muted" loop="loop">
            <source src="<?php echo htmlspecialchars($hero_video_url); ?>" type="video/mp4">
        </video>
    <?php endif; ?>
</header>

<div class="container">
    <!-- Upcoming Event Countdown -->
    <?php if (isset($event) && $event): ?>
    <div class="countdown-card text-center">
        <h3 class="mb-3"><?php echo htmlspecialchars($event['name']); ?></h3>
        <div id="countdown" class="display-4" style="font-weight: bold;"></div>
    </div>
    <?php endif; ?>
</div>

<div class="container my-5">
    <div class="row">
        <!-- Latest Sermon -->
        <div class="col-md-8">
            <h2 class="mb-4">Latest Sermon</h2>
            <?php if (isset($sermon) && $sermon): ?>
            <div class="card sermon-card">
                <div class="card-body">
                    <h5 class="card-title"><?php echo htmlspecialchars($sermon['title']); ?></h5>
                    <p class="card-text"><?php echo nl2br(htmlspecialchars(substr($sermon['content'], 0, 200))); ?>...</p>
                    <a href="media.php#post-<?php echo $sermon['id']; ?>" class="btn btn-primary" style="background-color: #007bff; border-color: #007bff;">Read More</a>
                </div>
            </div>
            <?php else: ?>
            <p>No sermon has been featured yet.</p>
            <?php endif; ?>
        </div>

        <!-- Announcements -->
        <div class="col-md-4">
            <h2 class="mb-4">Announcements</h2>
            <div class="list-group">
                <?php if ($announcements): ?>
                    <?php foreach ($announcements as $announcement): ?>
                        <a href="announcement.php?id=<?php echo $announcement['id']; ?>" class="list-group-item list-group-item-action flex-column align-items-start">
                            <div class="d-flex w-100 justify-content-between">
                                <h5 class="mb-1"><?php echo htmlspecialchars($announcement['title']); ?></h5>
                                <small><?php echo date('M d, Y', strtotime($announcement['created_at'])); ?></small>
                            </div>
                            <p class="mb-1">
                                <small>By <?php echo htmlspecialchars($announcement['author_email'] ?? 'Admin'); ?></small>
                            </p>
                            <small>Read More...</small>
                        </a>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p>No recent announcements.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php if (isset($event) && $event): ?>
<script>
    // Countdown Timer
    var countDownDate = new Date("<?php echo $event['start_time']; ?>").getTime();
    var x = setInterval(function() {
        var now = new Date().getTime();
        var distance = countDownDate - now;
        var days = Math.floor(distance / (1000 * 60 * 60 * 24));
        var hours = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
        var minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
        var seconds = Math.floor((distance % (1000 * 60)) / 1000);

        document.getElementById("countdown").innerHTML = days + "d " + hours + "h " + minutes + "m " + seconds + "s ";

        if (distance < 0) {
            clearInterval(x);
            document.getElementById("countdown").innerHTML = "EVENT STARTED";
        }
    }, 1000);
</script>
<?php endif; ?>

<?php require_once 'includes/public_footer.php'; ?>
