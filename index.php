<?php
session_start();

/**
 * index.php
 * 
 * A mobile-friendly page for workers to:
 *  - Log in (with 'worker' role)
 *  - Remember credentials (localStorage)
 *  - Register entrance/exit with geolocation
 */

require_once __DIR__ . '/db_init.php';
$pdo = getPDOConnection();

// 1) Handle logout
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_destroy();
    header("Location: index.php");
    exit;
}

// 2) If login form posted, try to validate
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
            // Must be role=worker to proceed
            if ($userRow['rol'] !== 'worker') {
                $loginError = "Este usuario no es de tipo 'worker'.";
            } else {
                // Logged in => set session
                $_SESSION['user_id']     = $userRow['id'];
                $_SESSION['user_name']   = $userRow['nombre'];
                $_SESSION['user_rol']    = $userRow['rol'];
                $_SESSION['empleado_id'] = $userRow['empleado_id'] ?? null; 

                header("Location: index.php");
                exit;
            }
        } else {
            $loginError = "Contraseña incorrecta.";
        }
    } else {
        $loginError = "Usuario no encontrado.";
    }
}

// 3) If not logged in, show the login form
if (!isset($_SESSION['user_id']) || $_SESSION['user_rol'] !== 'worker'):
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>jocarsa | darksalmon</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="darksalmon.png" type="image/x-icon">

    <!-- PNG Favicon for Browsers -->
    <link rel="icon" type="image/png" sizes="32x32" href="darksalmon.png">
    <link rel="icon" type="image/png" sizes="16x16" href="darksalmon.png">

    <!-- Apple Touch Icon (iOS) -->
    <link rel="apple-touch-icon" sizes="180x180" href="/darksalmon.png">
    <style>
    /* Mobile-friendly, darksalmon style */
    * {
      font-family: Ubuntu, sans-serif;
      box-sizing: border-box;
    }
    body {
      margin: 0; 
      padding: 0;
      height: 100vh;
      display: flex;
      justify-content: center;
      align-items: center;
      background: DarkSalmon;
      background: linear-gradient(0deg, rgba(124,80,65,1) 0%, rgba(233,150,122,1) 100%);
    }
    form {
      width: 280px;
      border: 1px solid lightgrey;
      border-radius: 150px 150px 5px 5px;
      box-shadow: 0px 4px 8px rgba(0,0,0,0.3);
      background: white;
      padding: 20px;
      display: flex;
      flex-direction: column;
      align-items: center;
    }
    form img {
      width: 100%;
      border-radius: 300px;
    }
    form h2 {
      margin-top: 0;
      color: DarkSalmon;
      text-align: center;
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
      font-size: 16px;
    }
    .login-btn:hover {
      filter: brightness(110%);
    }
    .error {
      color: red;
      margin-top: 10px;
      text-align: center;
    }
    .remember-container {
      margin: 10px 0;
      display: flex;
      align-items: center;
      gap: 5px;
      width: 100%;
    }
    </style>
</head>
<body>
    <form method="POST" onsubmit="onLoginSubmit()">
        <img src="darksalmon.png">
        <h2>Registro Entrada/Salida</h2>

        <?php if (!empty($loginError)): ?>
            <div class="error"><?= htmlspecialchars($loginError) ?></div>
        <?php endif; ?>

        <label for="username">Usuario:</label>
        <input type="text" name="username" id="username" required>

        <label for="password">Contraseña:</label>
        <input type="password" name="password" id="password" required>

        <div class="remember-container">
            <input type="checkbox" id="rememberMe">
            <label for="rememberMe" style="font-weight:normal;">Recordar credenciales</label>
        </div>

        <input class="relieve login-btn" type="submit" value="Iniciar sesión">
    </form>

    <script>
    // Load credentials from localStorage
    window.addEventListener('load', () => {
        const savedUser = localStorage.getItem('savedUser');
        const savedPass = localStorage.getItem('savedPass');
        if (savedUser && savedPass) {
            document.getElementById('username').value = savedUser;
            document.getElementById('password').value = savedPass;
            document.getElementById('rememberMe').checked = true;
        }
    });

    function onLoginSubmit() {
        const chk = document.getElementById('rememberMe');
        if (chk.checked) {
            localStorage.setItem('savedUser', document.getElementById('username').value);
            localStorage.setItem('savedPass', document.getElementById('password').value);
        } else {
            localStorage.removeItem('savedUser');
            localStorage.removeItem('savedPass');
        }
    }
    </script>
</body>
</html>
<?php
exit; // Stop here if not logged in as worker
endif;
?>

<!-- If worker is logged in, show the clock in/out interface -->
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <title>jocarsa | darksalmon</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="css/estilo.css">
  <style>
  img{width:50%;}
    body { 
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: flex-start;
      padding: 20px;
      min-height: 100vh;
    }
    h2 {
      margin-top: 10px;
      color: white;
      text-align: center;
    }
    .buttons {
      margin: 20px 0;
      display: flex;
      flex-direction: column;
      gap: 15px;
      width: 220px;
    }
    .buttons button,.logout button {
      padding: 15px;
      border-radius: 8px;
      border: none;
      font-size: 16px;
      cursor: pointer;
      background: darksalmon;
      color: #fff;
      width:100%;
    }
    .buttons button:hover {
      filter: brightness(110%);
    }
    .logout {
      
      text-align: center;
      width:220px;
    }
    .msg {
      margin: 10px auto;
      padding: 10px;
      color: white;
      background: #333;
      border-radius: 5px;
      display: none;
      text-align: center;
      width: 220px;
          box-sizing: border-box;
    }
  </style>
</head>
<body>
 <img src="darksalmon.png">
  <h2>jocarsa | darksalmon</h2>

  <div class="buttons">
    <button onclick="registrarEntrada()">Registrar Entrada</button>
    <button onclick="registrarSalida()">Registrar Salida</button>
  </div>
  
  <div class="msg" id="mensaje"></div>

  <div class="logout">
    <button style="background:#eee;color:#444;" onclick="window.location='?action=logout'">Cerrar sesión</button>
  </div>

  <script>
  // Attempt geolocation, then send lat/lon to API
  function registrarEntrada() {
    if (!navigator.geolocation) {
      alert("Geolocalización no soportada en este navegador.");
      return;
    }
    navigator.geolocation.getCurrentPosition(
      position => {
        clockAction('entrance', position.coords.latitude, position.coords.longitude);
      },
      err => {
        alert("No se pudo obtener geolocalización: " + err.message);
      }
    );
  }

  function registrarSalida() {
    if (!navigator.geolocation) {
      alert("Geolocalización no soportada en este navegador.");
      return;
    }
    navigator.geolocation.getCurrentPosition(
      position => {
        clockAction('exit', position.coords.latitude, position.coords.longitude);
      },
      err => {
        alert("No se pudo obtener geolocalización: " + err.message);
      }
    );
  }

  function clockAction(action, lat, lon) {
    fetch("api.php?endpoint=logTime", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ 
        action: action,
        latitude: lat,
        longitude: lon
      })
    })
    .then(r => r.json())
    .then(res => {
      const msgBox = document.getElementById('mensaje');
      if (res.error) {
        msgBox.innerText = "Error: " + res.error;
        msgBox.style.display = "block";
        msgBox.style.background = "#900";
      } else {
        msgBox.innerText = "Registrado (" + action + ") correctamente";
        msgBox.style.display = "block";
        msgBox.style.background = "#090";
      }
    })
    .catch(err => {
      alert("Ocurrió un error: " + err);
    });
  }
  </script>
</body>
</html>

