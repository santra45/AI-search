import requests
import os
from dotenv import load_dotenv

load_dotenv()

url = os.getenv("WC_LOCAL_URL")
ck = os.getenv("WC_CONSUMER_KEY_LOCAL")
cs = os.getenv("WC_CONSUMER_SECRET_LOCAL")

endpoint = f"{url}/wp-json/wc/v3/products"

params = {
    "consumer_key": ck,
    "consumer_secret": cs
}

r = requests.get(endpoint, params=params)

print(r.status_code)
print(r.text[:500])