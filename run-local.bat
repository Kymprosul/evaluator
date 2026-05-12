@echo off
setlocal

set "ROOT_DIR=%~dp0"
set "PUBLIC_DIR=%ROOT_DIR%public"
set "HOST=127.0.0.1"
set "PORT=8080"

where php >nul 2>&1
if errorlevel 1 (
    echo [ERROR] No se encontro PHP en PATH.
    echo Instala PHP o agrega php.exe al PATH y vuelve a intentar.
    exit /b 1
)

if not exist "%PUBLIC_DIR%\index.php" (
    echo [ERROR] No se encontro %PUBLIC_DIR%\index.php
    echo Ejecuta este script desde la raiz del proyecto.
    exit /b 1
)

echo.
echo Iniciando Evaluator en http://%HOST%:%PORT% ...
echo (Ctrl + C para detener)
echo.

start "" "http://%HOST%:%PORT%/"
php -S %HOST%:%PORT% -t "%PUBLIC_DIR%"

endlocal
