package main

import (
	"context"
	"encoding/json"
	"flag"
	"fmt"
	"log"
	"time"

	"github.com/tess1o/tapo-go"
)

func main() {
	ip := flag.String("ip", "", "Tapo IP Address")
	email := flag.String("email", "", "TP-Link Email (ID)")
	password := flag.String("password", "", "TP-Link Password")
	flag.Parse()

	if *ip == "" || *email == "" || *password == "" {
		log.Fatal("Usage: tapo-cli -ip <ip> -email <email> -password <password>")
	}

	fmt.Printf("Connecting to %s as %s...\n", *ip, *email)

	ctx, cancel := context.WithTimeout(context.Background(), 30*time.Second)
	defer cancel()

	plug, err := tapo.NewSmartPlug(ctx, *ip, *email, *password, tapo.Options{
		RetryConfig: tapo.DefaultRetryConfig,
	})
	if err != nil {
		log.Fatalf("Error creating smart plug client: %v", err)
	}
	fmt.Println("Connected!")

	fmt.Println("Fetching energy usage...")
	energy, err := plug.GetEnergyUsage(ctx)
	if err != nil {
		log.Fatalf("Error getting energy usage: %v", err)
	}

	pretty, _ := json.MarshalIndent(energy.Result, "", "  ")
	fmt.Printf("Energy Usage:\n%s\n", string(pretty))
}
