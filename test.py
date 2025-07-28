import requests
import json
from datetime import datetime, timezone
import base64
from PIL import Image
import io

# API Configuration
AUTH_URL = "https://openapi-sandbox.kasikornbank.com/v1/oauth/token"
QR_URL = "https://openapi-sandbox.kasikornbank.com/v1/qrpayment/request"

# Credentials from the exercise
CONSUMER_ID = "MT2ZIR9BkGpgPCHQi2JOzkvZCwGHeMb3"
CONSUMER_SECRET = "dIXWNm0GM0Cv0kW6"

def get_access_token():
    """Obtain OAuth2 access token using consumer credentials"""
    auth_payload = {
        "grant_type": "client_credentials",
        "client_id": CONSUMER_ID,
        "client_secret": CONSUMER_SECRET
    }
    
    try:
        response = requests.post(AUTH_URL, data=auth_payload)
        response.raise_for_status()
        return response.json()["access_token"]
    except Exception as e:
        print(f"❌ Token acquisition failed: {e}")
        print("Response:", response.text)
        exit()

def generate_qr_code(access_token):
    """Generate Thai QR Code using KBank API"""
    headers = {
        "Authorization": f"Bearer {access_token}",
        "Content-Type": "application/json",
        "x-test-mode": "true",
        "env-id": "QR002"
    }

    # Generate current timestamp in ISO 8601 format
    request_time = datetime.now(timezone.utc).isoformat(timespec='milliseconds')

    payload = {
        "partnerTxnUid": "PARTNERTEST0001",
        "partnerId": "PTR1051673",
        "partnerSecret": "d4bded59200547bc85903574a293831b",
        "requestDt": request_time,
        "merchantId": "KB102057149704",
        "qrType": 3,
        "amount": 100.00,
        "currencyCode": "THB",
        "reference1": "INV001",
        "reference2": "HELLOWORLD",
        "reference3": "INV001",
        "reference4": "INV001"
    }

    try:
        response = requests.post(QR_URL, headers=headers, json=payload)
        response.raise_for_status()
        return response.json()
    except Exception as e:
        print(f"❌ QR generation failed: {e}")
        print("Response:", response.text if response else "")
        exit()

def display_qr_image(qr_response):
    """Display QR code image from base64 encoded string"""
    if "qrImage" in qr_response.get("data", {}):
        image_data = base64.b64decode(qr_response["data"]["qrImage"])
        img = Image.open(io.BytesIO(image_data))
        img.show()
        print("✅ QR Code image displayed!")
    else:
        print("⚠️ No QR image found in response")

def main():
    # Step 1: Get access token
    print("🔑 Obtaining access token...")
    access_token = get_access_token()
    print(f"✅ Token obtained: {access_token[:15]}...")
    
    # Step 2: Generate QR code
    print("\n🔄 Generating Thai QR Code...")
    qr_response = generate_qr_code(access_token)
    
    # Step 3: Process response
    print("\n📄 API Response:")
    print(json.dumps(qr_response, indent=2))
    
    # Step 4: Display QR code if available
    if qr_response.get("status") == "SUCCESS":
        print("\n🖼️ Displaying QR Code...")
        display_qr_image(qr_response)
    else:
        print("❌ QR generation failed")

if __name__ == "__main__":
    main()