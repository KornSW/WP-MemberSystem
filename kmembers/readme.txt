=== KornSW MemberSystem ===
Contributors: KornSW
Requires at least: 6.2
Requires PHP: 7.4
Stable tag: 1.3.1

Memberbereiche, Rollenverwaltung, geschützte Inhalte und eigener zweistufiger Login.

== Installation ==
1. ZIP unter Plugins > Installieren hochladen und aktivieren.
2. Unter Benutzer > MemberSystem (KornSW) konfigurieren.
3. Falls der Login-Pfad geändert wurde, Einstellungen > Permalinks einmal speichern.

== Shortcodes ==
[kMemberEmbed site="slug" fallback="fallback-slug" flow_identifier="Whitepaper Download"]
[kMemberLogonState]

== Passwortstatus ==
KornSW MemberSystem kennzeichnet selbst angelegte passwortlose Konten mit User-Meta _kmembers_password_state=generated.
Konten ohne Marker gelten als Konten mit vorhandenem Passwort.


== Changelog ==

= 1.3.0 =
* Öffentliche Umbenennung in KornSW MemberSystem; Admin-Menü unter Benutzer > MemberSystem (KornSW).
* Neuer Standard-Loginpfad /member-login/.
* Anmelde-Links verwenden ?member-login-token=TOKEN.
* Optionale Account-Erweiterungsseite kann oberhalb der eigenen Profilfelder eingebettet werden.


= 1.2.1 =
* Absendername und Absender-E-Mail-Adresse der Anmelde-Link-Mails konfigurierbar.
* Standard-Absender: Seitentitel und noreply@Website-Domain.


= 1.2.0 =
* Rollenabhängige Steuerung der WordPress-Adminleiste.
* Eigene Frontend-Accountverwaltung für Profil, Passwort und Löschanforderung.
* Versandbestätigung blendet das E-Mail-Formular zuverlässig aus.


= 1.1.1 =
* Verbesserte Formularbedienung, Registrierungsdarstellung und sichere Bereinigung nie verwendeter MemberSystem-Konten.

= 1.1.0 =
* Sichere Registrierung ohne Passwortvergabe vor dem ersten Anmelde-Link.
* Einmaliges optionales Passwortangebot erst nach erfolgreichem Anmelde-Link-Login.
* Anzeigename statt Spitzname; Vor- und Nachname optional als Pflichtfelder konfigurierbar.


== Integrations-Contract 1.4 ==
Filter: member_login_email_send
Ein externer Handler darf true nur zurückgeben, wenn der Versand erfolgreich übernommen wurde. Bei false verwendet das MemberSystem wp_mail() als Fallback. Context: email, login_base_url, token_query_param, token, source_url, target_url, is_initial_signup, flow_identifier, fields.


== Defer Onboarding (1.4.2) ==
Seiten/Beiträge können im MemberSystem-Zugriffsblock 'Defer Onboarding' aktivieren. Der Wert wird in den einmaligen Login-Token übernommen. Bei erfolgreichem Token-Login wird das einmalige Passwortangebot für genau diesen Flow übersprungen; der Login zählt dennoch als erfolgreicher Login und schützt vor Cleanup. Der Shortcode kann mit defer_onboarding="true" oder "false" explizit überschreiben.


== WordPress-Login-Customizing (1.4.3) ==
Der normale GET/HEAD-Aufruf von wp-login.php?action=login kann nun (standardmäßig aktiv) als MemberSystem-Login gerendert werden. Die UI nutzt den originalen WordPress-Hook login_form für Erweiterungen anderer Plugins. Ein request-interner Guard verhindert rekursives Einsammeln. Andere WordPress-Loginaktionen, interim-login und reauth bleiben nativ. 'Weitere Anmeldemöglichkeiten' und der bestehende MFA-/Security-Fallback verwenden kmembers_native_login=1 und umgehen die MemberSystem-Vorschaltseite explizit.


== Login-UI-Kompatibilität (1.4.5) ==
Der Link 'Weitere Anmeldemöglichkeiten' wird ausschließlich im von MemberSystem angepassten wp-login.php-Hauptlogin gerendert. Die eigene MemberSystem-Route und eingebettete Login-UI zeigen ihn nicht. Low-Level-Erweiterungen des originalen WordPress-Hooks login_form werden in allen MemberSystem-Login-UIs direkt eingebettet. Für fremde Plugins stehen dabei die nativen DOM-Anker #login (außerhalb wp-login.php, wo WordPress ihn bereits liefert) und form#loginform bereit; das Kompatibilitätsformular postet mit Native-Bypass auf wp-login.php und erhält redirect_to/testcookie.
