---
title: Installation
---


# Installation

## Einführung

In unserem Manual zeigen wir Ihnen Schritt für Schritt, wie Sie das Plugin installieren und einrichten können. In unterschiedlichen Shopware-Umgebungen können trotz umfangreicher Softwaretests immer wieder kleine Bugs auftreten. Solche Bugfixes erledigen wir schnell und unkompliziert, in der Regel noch am selben Tag.

## Support

Sollten bei der Einrichtung Ihres "TopCONNECTOR" Probleme oder Fragen auftauchen, kontaktieren Sie uns bitte unter: shopware@topdata.de.

## Wichtiger Hinweis

Bei jeder Installation bzw. jedem Update eines Plugins in Ihrem Shopware-Shop, handelt es sich um eine Änderung der Software. Trotz sämtlicher von uns oder Shopware durchgeführter Maßnahmen zur Qualitätssicherung können wir nicht ausschließen, dass es während der Installation oder des Updates in Ausnahmefällen zu Problemen kommen kann.

Wir empfehlen Ihnen daher grundsätzlich:
* Neue Plugins bzw. Updates von bereits installierten Plugins zunächst in einer geeigneten Testumgebung zu testen
* Diese nicht direkt in die Live-Umgebung einzuspielen
* Ein regelmäßiges Backup Ihrer Live-Umgebung wird ebenfalls dringend empfohlen

## Systemvoraussetzungen

* Linux-basiertes Betriebssystem mit Apache 2.2 oder 2.4
* Webserver mit mod_rewrite Modul und Möglichkeit auf .htaccess Zugriff
* PHP 7.2.x / 7.3.x (7.2.20 und 7.3.7 sind nicht kompatibel)
* MySQL >= 5.7 (außer 8.0.20 und 8.0.21)
* Möglichkeit Cronjobs einzurichten
* Mindestens 4 GB freier Speicherplatz
* Es wird ein Shell-Zugang benötigt, um den Import zu starten
* Empfohlen: memory_limit > 512M


## Die Einrichtung Ihres "TopCONNECTOR" Schritt für Schritt

1. Downloaden Sie "TopCONNECTOR" kostenlos im Shopware-Community Store
2. Bitte führen Sie den Bestellvorgang durch in dem Sie "TopCONNECTOR" in den Warenkorb legen und den Bestellprozess an der Kasse abschliessen
3. Laden Sie "TopCONNECTOR" im Adminbereich Ihres Shopware-Shops unter dem Menüpunkt "Einkäufe" und installieren Sie das Plugin anschließend unter dem Menüpunkt "Meine Plugins"
4. Im nächsten Schritt aktivieren Sie Ihren "TopCONNECTOR"
5. Abschließend konfigurieren Sie Ihren "TopCONNECTOR" ganz nach Wunsch



## Webservice Zugangsdaten
Nach der Installation des Plugins müssen Sie die API-Zugangsdaten eingeben, um eine Verbindung zum TopData Webservice herzustellen.

Einstellungen - System - Plugins - TopdataConnector Menü (... auf der rechten Seite) - Konfiguration

TopData stellt Ihnen folgende Daten zur Verfügung:

- API Benutzer-ID
- API Passwort
- API Security Key

### Demo Zugangsdaten

Wenn Sie das Plugin mit Demo-Zugangsdaten testen möchten, können Sie folgende Daten verwenden:

- API Benutzer-ID: 6
- API Passwort: nTI9kbsniVWT13Ns
- API Security Key: oateouq974fpby5t6ldf8glzo85mr9t6aebozrox