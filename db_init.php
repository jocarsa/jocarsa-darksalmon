<?php
/**
 * db_init.php
 * 
 * Responsible for:
 * 1) Creating or opening the SQLite database.
 * 2) Creating any missing tables.
 * 3) Inserting sample data if the DB is brand new.
 */

function getPDOConnection(): PDO
{
    $dbFile = '../databases/darksalmon.db';
    $dbExisted = file_exists($dbFile);

    try {
        $pdo = new PDO("sqlite:" . $dbFile);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // Enable foreign keys
        $pdo->exec("PRAGMA foreign_keys = ON;");
    } catch (PDOException $e) {
        die("Error connecting to DB: " . $e->getMessage());
    }

    // If database file was just created, run table creation & sample inserts
    if (!$dbExisted) {
        crearTablas($pdo);
        insertarDatosEjemplo($pdo);
    }

    return $pdo;
}

function crearTablas(PDO $pdo)
{
    try {
        // New table: centros_trabajo
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS centros_trabajo (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT,
                address TEXT,
                latitude REAL,
                longitude REAL
            );
        ");

        // USUARIOS table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS usuarios (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                nombre TEXT,
                email TEXT,
                username TEXT UNIQUE,
                password TEXT,
                rol TEXT,
                empleado_id INTEGER
            );
        ");

        // Table for contract types
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS tipos_contrato (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT
            );
        ");

        // Modified Empleados table: add centro_trabajo_id and radio_accion
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS empleados (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                nombre TEXT,
                apellido TEXT,
                departamento TEXT,
                tipo_contrato_id INTEGER,
                centro_trabajo_id INTEGER,
                radio_accion REAL,
                FOREIGN KEY(tipo_contrato_id) REFERENCES tipos_contrato(id) ON DELETE SET NULL,
                FOREIGN KEY(centro_trabajo_id) REFERENCES centros_trabajo(id) ON DELETE SET NULL
            );
        ");

        // Asistencia table (with lat/lon columns for entrance/salida)
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS Asistencia (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                empleado_id INTEGER,
                fecha TEXT,         -- YYYY-MM-DD
                hora_entrada TEXT,  -- HH:MM
                hora_salida TEXT,   -- HH:MM
                lat_entrada REAL,
                lon_entrada REAL,
                lat_salida REAL,
                lon_salida REAL,
                FOREIGN KEY(empleado_id) REFERENCES empleados(id) ON DELETE CASCADE
            );
        ");

        // Vacaciones table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS Vacaciones (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                empleado_id INTEGER,
                fecha_inicio TEXT,  -- YYYY-MM-DD
                fecha_fin TEXT,     -- YYYY-MM-DD
                aprobado INTEGER,   -- 0 = No, 1 = Si
                FOREIGN KEY(empleado_id) REFERENCES empleados(id) ON DELETE CASCADE
            );
        ");

        // Contratos table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS Contratos (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                empleado_id INTEGER,
                tipo_contrato TEXT,
                fecha_firma TEXT,   -- YYYY-MM-DD
                salario_anual REAL,
                FOREIGN KEY(empleado_id) REFERENCES empleados(id) ON DELETE CASCADE
            );
        ");

        // Incidencias table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS Incidencias (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                empleado_id INTEGER,
                tipo TEXT,
                fecha TEXT,         -- YYYY-MM-DD
                FOREIGN KEY(empleado_id) REFERENCES empleados(id) ON DELETE CASCADE
            );
        ");
    } catch (PDOException $e) {
        die("Error creating schema: " . $e->getMessage());
    }
}

function insertarDatosEjemplo(PDO $pdo)
{
    // Insert default admin user (plaintext password here for demo)
    $pdo->exec("
        INSERT INTO usuarios (nombre, email, username, password, rol)
        VALUES (
            'Admin Darksalmon',
            'admin@company.com',
            'jocarsa',
            'jocarsa',
            'admin'
        )
    ");

    // Insert contract types
    $pdo->exec("INSERT INTO tipos_contrato (name) VALUES ('Indefinido'), ('Temporal'), ('Prácticas')");

    // Insert a sample centro de trabajo
    $pdo->exec("
        INSERT INTO centros_trabajo (name, address, latitude, longitude)
        VALUES ('Central Office', '123 Main St, City', 40.7128, -74.0060)
    ");
    $centroId = $pdo->lastInsertId();

    // Insert sample employees with centro de trabajo and radio de acción
    $pdo->exec("
        INSERT INTO empleados (nombre, apellido, departamento, tipo_contrato_id, centro_trabajo_id, radio_accion)
        VALUES
        ('Carlos', 'García', 'IT', 1, $centroId, 5.0),
        ('María', 'Pérez', 'Marketing', 2, $centroId, 10.0)
    ");

    // Example employee for our 'worker'
    $pdo->exec("
        INSERT INTO empleados (nombre, apellido, departamento, tipo_contrato_id, centro_trabajo_id, radio_accion)
        VALUES
        ('Worker', 'Man', 'Operaciones', 1, $centroId, 8.0)
    ");
    $lastEmpId = $pdo->lastInsertId();

    // Insert the 'worker' user referencing that employee
    $pdo->exec("
        INSERT INTO usuarios (nombre, email, username, password, rol, empleado_id)
        VALUES (
            'Worker Man',
            'worker@company.com',
            'worker',
            'secret',
            'worker',
            $lastEmpId
        )
    ");
}
?>

