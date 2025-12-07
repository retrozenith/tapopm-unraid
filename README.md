# tapopm-unraid

With this UnRaid plugin you can turn a TP-Link Tapo P110 device into an energy monitor for your server.
This is a conversion of the original `tasmotapm-unraid` plugin.

## Introduction

This plugin specifically supports the Tapo P110 smart plug which offers energy monitoring.
It uses key parts of the Tapo "KLAP" protocol (local encrypted communication) to fetch energy stats securely.

> [!WARNING]
> Please ensure your server's BIOS is set to "Always On" or similar power-loss settings to avoid accidental shutdowns if the plug state toggles.
> This plugin performs read-only operations (checking energy usage), but using smart plugs on servers always carries some risk.

## Configuration

Unlike Tasmota, Tapo devices require a strict authentication handshake involving your TP-Link ID (Email) and Password, even for local communication.

1. Install the plugin.
2. Go to **Settings -> Tapo Power Monitor**.
3. Enter your **Device IP**.
4. Enter your **TP-Link ID (Email)** and **Password**.
5. Set your electricity cost parameters.

## Usage

Plugins > Install Plugin
```
https://raw.githubusercontent.com/Victor/tapopm-unraid/main/tapopm.plg
```
