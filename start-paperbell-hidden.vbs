Option Explicit

Dim shell
Dim command
Dim exitCode
Dim fileSystem
Dim launcher

Set shell = CreateObject("WScript.Shell")
Set fileSystem = CreateObject("Scripting.FileSystemObject")
launcher = fileSystem.BuildPath(fileSystem.GetParentFolderName(WScript.ScriptFullName), "start-paperbell.ps1")
command = "powershell.exe -NoLogo -NoProfile -NonInteractive -WindowStyle Hidden -ExecutionPolicy Bypass -File """ & launcher & """"

' Window style 0 runs PowerShell without creating a visible console window.
exitCode = shell.Run(command, 0, True)
WScript.Quit exitCode
