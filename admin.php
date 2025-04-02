<?php
session_start();

// 1) Load the DB init. This ensures the DB exists with all tables:
require_once __DIR__ . '/db_init.php';
$pdo = getPDOConnection();

// 2) Handle logout
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_destroy();
    header("Location: admin.php");
    exit;
}

// 3) If login form posted, try to validate
if (isset($_POST['username']) && isset($_POST['password'])) {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);

    // Check in DB
    $sql = "SELECT * FROM usuarios WHERE username = :u LIMIT 1";
    $st = $pdo->prepare($sql);
    $st->execute([':u' => $username]);
    $userRow = $st->fetch(PDO::FETCH_ASSOC);

    if ($userRow) {
        if ($password === $userRow['password']) {
            // Logged in => set session
            $_SESSION['user_id']   = $userRow['id'];
            $_SESSION['user_name'] = $userRow['nombre'];
            $_SESSION['user_rol']  = $userRow['rol'];

            header("Location: admin.php");
            exit;
        } else {
            $loginError = "Contraseña incorrecta.";
        }
    } else {
        $loginError = "Usuario no encontrado.";
    }
}

// 4) If not logged in, show the login form
if (!isset($_SESSION['user_id'])):
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8" />
    <title>jocarsa | darksalmon</title>
    <link rel="icon" href="darksalmon.png" type="image/x-icon">

    <!-- PNG Favicon for Browsers -->
    <link rel="icon" type="image/png" sizes="32x32" href="darksalmon.png">
    <link rel="icon" type="image/png" sizes="16x16" href="darksalmon.png">

    <!-- Apple Touch Icon (iOS) -->
    <link rel="apple-touch-icon" sizes="180x180" href="/darksalmon.png">
    <style>
    *{
      font-family: Ubuntu, sans-serif;
    }
    html, body {
        padding: 0; 
        margin: 0;
        height: 100%;
        background: DarkSalmon;
        background: linear-gradient(0deg, rgba(124,80,65,1) 0%, rgba(233,150,122,1) 100%);
    }
    body {
        display: flex;
        justify-content: center;
        align-items: center;
    }
    form {
        width: 250px;
        border: 1px solid lightgrey;
        border-radius: 150px 150px 5px 5px;
        box-shadow: 0px 4px 8px rgba(0,0,0,0.3);
        background: white;
        padding: 20px;
        font-size: 12px;
        display: flex;
        flex-direction: column;
        align-items: center;
    }
    form img {
        width:100%;
        border-radius:300px;
        box-sizing:content-box;
    }
    form h2 {
        margin-top: 0;
        color: DarkSalmon;
    }
    label {
        width: 100%;
        margin-top: 10px;
        font-weight: bold;
    }
    input[type="text"],
    input[type="password"] {
        width: 100%;
        padding: 10px;
        border: 1px solid lightgrey;
        border-radius: 5px;
        box-shadow: inset 0px 4px 8px rgba(0,0,0,0.1);
        margin-top: 5px;
        box-sizing:border-box;
    }
    .relieve {
        background-color: rgba(0,0,0,0.3);
        box-shadow: 0 4px 0 rgba(255,255,255,0.7),
                    0 6px 6px rgba(0,0,0,0.2);
        transition: all 0.2s ease;
        border: none;
    }
    .relieve:active {
        box-shadow: 0 0 0 #357ab7,
                    0 2px 4px rgba(0,0,0,0.2);
    }
    .login-btn {
        width: 100%;
        padding: 10px;
        border-radius: 5px;
        margin-top: 15px;
        cursor: pointer;
        color: white;
        background: darksalmon;
    }
    .login-btn:hover {
        filter: brightness(110%);
    }
    .error {
        color: red;
        margin-top: 10px;
    }
    </style>
</head>
<body>
    <form method="POST">
        <img src="darksalmon.png">
        <h2>jocarsa | darksalmon</h2>
        <?php if (!empty($loginError)): ?>
            <div class="error"><?= htmlspecialchars($loginError) ?></div>
        <?php endif; ?>
        <label for="username">Usuario:</label>
        <input type="text" name="username" id="username" required>

        <label for="password">Contraseña:</label>
        <input type="password" name="password" id="password" required>

        <input class="relieve login-btn" type="submit" value="Iniciar sesión">
    </form>
</body>
</html>
<?php
exit; // stop here if not logged in
endif;
?>

<!-- ----------------------------------------------------------
     5) If logged in, show the original DarkSalmon dashboard
     ---------------------------------------------------------- -->
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>jocarsa | darksalmon</title>
  <link rel="stylesheet" href="css/estilo.css">
  <link rel="icon" href="darksalmon.png" type="image/x-icon">

    <!-- PNG Favicon for Browsers -->
    <link rel="icon" type="image/png" sizes="32x32" href="darksalmon.png">
    <link rel="icon" type="image/png" sizes="16x16" href="darksalmon.png">

    <!-- Apple Touch Icon (iOS) -->
    <link rel="apple-touch-icon" sizes="180x180" href="/darksalmon.png">
</head>
<body>
  <header>
    <h1>
      <img src="darksalmon.png" alt="Logo">
      jocarsa | darksalmon
    </h1>
    <div style="display:flex; gap:5px;">
      <button class="relieve">A</button>
      <button class="relieve">A</button>
      <!-- Logout button -->
      <button class="relieve" onclick="window.location='?action=logout'">🔒</button>
    </div>
  </header>
  <main>
    <nav>
      <div class="enlaces"></div>
      <div id="ocultar">
        <span class="icono relieve">></span>Ocultar
      </div>
    </nav>
    <section>
      <h3 id="titulo-tabla">Vista</h3>
      <button id="btn-nuevo" class="btn relieve" style="margin-bottom:10px;">+</button>
      <table>
        <thead><tr></tr></thead>
        <tbody></tbody>
      </table>
      <!-- Dynamic Insert/Edit form -->
      <div id="formulario">
        <h4 id="form-title"></h4>
        <form>
          <input type="hidden" id="record-id">
          <div id="campos"></div>
          <button type="button" id="guardar" class="btn">Guardar</button>
          <button type="button" id="cancelar" class="btn">Cancelar</button>
        </form>
      </div>
    </section>
  </main>
  <footer>
    <p>(c) 2025 jocarsa | darksalmon</p>
  </footer>

  <script src="js/codigo.js"></script>
</body>
</html>

