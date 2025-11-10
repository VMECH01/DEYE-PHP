Perfect 👍 — here’s a clear **flow diagram** showing how your PHP script routes and processes API requests.

---

## 🧭 **Flow Overview**

```
           ┌────────────────────────────┐
           │     Incoming HTTP POST     │
           │   (JSON body in request)   │
           └──────────────┬─────────────┘
                          │
                          ▼
              ┌────────────────────────┐
              │ Read and decode JSON   │
              │ $formdata = json_decode│
              └──────────────┬─────────┘
                             │
                             ▼
        ┌────────────────────────────────────┐
        │ Check handshake: does "uid" exist? │
        └──────────────────┬─────────────────┘
                           │
               ┌───────────┴────────────┐
               │                        │
        (❌ No UID)               (✅ UID present)
   ┌────────────────┐        ┌─────────────────────────┐
   │ HTTP 401 Error  │        │ Continue request routing │
   │ "Handshake fail"│        └───────────────┬─────────┘
   └────────────────┘                        │
                                             ▼
         ┌──────────────────────────────────────────────────┐
         │ Does request contain start_time, end_time, instid?│
         └───────────────────────┬───────────────────────────┘
                                 │
                 (✅ Yes → Victron request)
                                 │
                                 ▼
         ┌──────────────────────────────────────────────┐
         │ Call Victron VRM API using cURL              │
         │   → build URL with timestamps                │
         │   → add X-Authorization header               │
         │   → return stats JSON                        │
         └──────────────────────────────────────────────┘
                                 │
                           (send JSON response)
                                 │
                                 ▼
         ┌──────────────────────────────────────────────┐
         │   If not Victron, check deye_action next     │
         └───────────────────────┬──────────────────────┘
                                 │
               ┌────────────────┴─────────────────┐
               │                                  │
      (deye_action = "get_stations")    (deye_action = "get_summary")
               │                                  │
               ▼                                  ▼
┌──────────────────────────────┐   ┌────────────────────────────────┐
│ Create DeyeCloudService()    │   │ Create DeyeCloudService()      │
│ → call getStations()         │   │ → call getStationSummary()     │
│ Return list + total count    │   │ Return today/yesterday/month   │
└──────────────────────────────┘   └────────────────────────────────┘
               │                                  │
               └──────────────┬───────────────────┘
                              │
                              ▼
             ┌──────────────────────────────────────┐
             │ (deye_action = "get_devices")        │
             │ → call getStationDevices()           │
             │ → return list of connected inverters │
             └──────────────────────────────────────┘
                              │
                              ▼
               ┌──────────────────────────────────┐
               │ If no case matched → HTTP 400    │
               │ "Invalid request parameters"      │
               └──────────────────────────────────┘
```

---

## 🧩 **Explanation of branches**

| Decision                              | Meaning                        | Action Taken                            |
| ------------------------------------- | ------------------------------ | --------------------------------------- |
| `!isset(uid)`                         | Missing handshake key          | Reject with 401                         |
| `isset(start_time, end_time, instid)` | It’s a Victron request         | Fetch VRM stats                         |
| `deye_action = get_stations`          | Ask for all Deye stations      | List stations                           |
| `deye_action = get_summary`           | Ask for energy summary         | Return today / yesterday / month totals |
| `deye_action = get_devices`           | Ask for all devices in station | Return device list                      |
| else                                  | Doesn’t match any of the above | Return 400 error                        |

---

## ⚙️ **Data flow summary**

**Frontend → Your PHP API → External API → Response**

| From         | To                  | Purpose                            |
| ------------ | ------------------- | ---------------------------------- |
| Frontend     | Your PHP endpoint   | Sends JSON (with uid + parameters) |
| PHP          | Victron or Deye API | Makes authenticated request        |
| External API | PHP                 | Returns energy / station data      |
| PHP          | Frontend            | Sends final JSON back to user      |

---

## 🧠 In one sentence:

> Your script acts as a **router + security gatekeeper** between your frontend and two backend services (Victron & Deye). It decides what to do based on the incoming JSON, validates it, calls the proper API, and sends the result back in clean JSON format.

---

Would you like me to draw this same flow as a **visual chart (image format)** so you can include it in documentation or diagrams?
