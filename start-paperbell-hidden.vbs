Option Explicit

Dim shell
Dim command
Dim exitCode

Set shell = CreateObject("WScript.Shell")
command = "powershell.exe -NoLogo -NoProfile -NonInteractive -WindowStyle Hidden -ExecutionPolicy Bypass -File ""C:\xampp\htdocs\paperbell\start-paperbell.ps1"""

' Window style 0 runs PowerShell without creating a visible console window.
exitCode = shell.Run(command, 0, True)
WScript.Quit exitCode
