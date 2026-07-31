<?php
// Default admin username and password:
$config = array(
  'admin_user' => 'admin',
  'admin_pass' => '$2y$10$YKIyWAmnQLtiJAy6QgHQ.eCpY4m.HCEbiHaTgN6.acNC6bDElzt.i'
);

// Can be overridden by what's in this file, if it exists:
if(file_exists(RASPI_ADMIN_DETAILS)) {
    if ( $auth_details = fopen(RASPI_ADMIN_DETAILS, 'r') ) {
      $config['admin_user'] = trim(fgets($auth_details));
      $config['admin_pass'] = trim(fgets($auth_details));
      fclose($auth_details);
    }
}


// PiSky's public view never includes this file. Every script that does include
// it is an administration endpoint and must remain protected even if an
// inherited Allsky configuration previously disabled WebUI authentication.
$useLogin = true;

// Check the PiSky administration session.
if ($useLogin) {
	if (session_status() !== PHP_SESSION_ACTIVE) session_start();
	if (isset($_GET["pisky_logout"])) {
		$_SESSION = array();
		session_regenerate_id(true);
		header("Location: /admin/");
		exit;
	}

	$loginError = "";
	if (isset($_POST["pisky_login"])) {
		$user = isset($_POST["username"]) && !is_array($_POST["username"])
			? trim(strval($_POST["username"])) : "";
		$pass = isset($_POST["password"]) && !is_array($_POST["password"])
			? strval($_POST["password"]) : "";
		$validated = hash_equals(strval($config["admin_user"]), $user)
			&& password_verify($pass, $config["admin_pass"]);
		if ($validated) {
			session_regenerate_id(true);
			$_SESSION["pisky_authenticated"] = true;
			header("Location: /admin/");
			exit;
		}
		$loginError = "The username or password was not recognised.";
	}

	if (empty($_SESSION["pisky_authenticated"])) {
		http_response_code(401);
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<title>Sign in — PiSky</title>
	<link href="/css/pisky.css" rel="stylesheet">
</head>
<body class="pisky-login">
	<main class="pisky-login-shell">
		<section class="pisky-glass pisky-login-card">
			<a class="pisky-login-brand" href="/" aria-label="Return to the public PiSky view">
				<span class="pisky-brand-mark"><span></span></span>
				<span><strong>PiSky</strong><small>Observatory control</small></span>
			</a>
			<span class="pisky-eyebrow">Authorised hosts only</span>
			<h1>Welcome back.</h1>
			<p>Sign in to configure this station's enabled observation capabilities.</p>
			<?php if ($loginError !== "") { ?>
				<div class="pisky-login-error"><?php echo htmlspecialchars($loginError); ?></div>
			<?php } ?>
			<form method="post" action="/admin/" autocomplete="on">
				<input type="hidden" name="pisky_login" value="1">
				<label>
					<span>Username</span>
					<input type="text" name="username" autocomplete="username" required autofocus>
				</label>
				<label>
					<span>Password</span>
					<input type="password" name="password" autocomplete="current-password" required>
				</label>
				<button class="btn btn-primary" type="submit">Sign in to PiSky</button>
			</form>
			<footer><a href="/">Return to the public sky view</a></footer>
		</section>
	</main>
</body>
</html>
<?php
		exit;
	}
}
?>
