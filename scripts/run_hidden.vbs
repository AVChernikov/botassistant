' Run a command with window style 0 (no console flash).
' Usage:
'   wscript.exe //nologo //B run_hidden.vbs <exe> <args...>
' Example:
'   wscript.exe //nologo //B run_hidden.vbs powershell.exe -NoProfile -File C:\path\cron.ps1

Option Explicit
Dim sh, cmd, i
If WScript.Arguments.Count < 1 Then
  WScript.Quit 2
End If
cmd = ""
For i = 0 To WScript.Arguments.Count - 1
  If i > 0 Then cmd = cmd & " "
  cmd = cmd & QuoteArg(WScript.Arguments(i))
Next
Set sh = CreateObject("Wscript.Shell")
' 0 = hidden, True = wait for exit
WScript.Quit sh.Run(cmd, 0, True)

Function QuoteArg(ByVal s)
  If InStr(s, " ") > 0 Or InStr(s, vbTab) > 0 Then
    QuoteArg = """" & Replace(s, """", """""") & """"
  Else
    QuoteArg = s
  End If
End Function
