# Hệ Thống Tải Lên Và Truyền Phát Video HLS (Laravel + Filament + MinIO + Docker)

Dự án này là một hệ thống hoàn chỉnh cho phép tải lên các video định dạng MP4, tự động chuyển đổi sang định dạng phát luồng thích ứng HLS (HTTP Live Streaming) với nhiều mức chất lượng (360p, 480p, 720p) bằng công cụ FFmpeg, lưu trữ trực tiếp trên Local MinIO (S3-compatible Object Storage), và phát video mượt mà trên giao diện quản trị Filament Admin bằng trình phát video HLS.js.

Toàn bộ hệ thống được container hóa hoàn toàn bằng Docker Compose giúp dễ dàng thiết lập và chạy thử nghiệm dưới môi trường Local.

---

### Chi tiết vai trò từng dịch vụ trong `docker-compose.yml`:
1.  **`laravel_app` (app)**: Container chạy PHP-FPM chứa mã nguồn ứng dụng Laravel. Nơi xử lý logic ứng dụng, Filament admin panel, và đẩy các tác vụ nặng vào Queue.
2.  **`laravel_nginx` (web)**: Nginx Web Server đóng vai trò tiếp nhận các yêu cầu HTTP từ trình duyệt của người dùng (ở cổng `8000`), sau đó chuyển tiếp xử lý cho PHP-FPM.
3.  **`laravel_queue` (queue)**: Queue Worker chạy liên tục ngầm bằng lệnh `php artisan queue:work`. Container này sử dụng chung cấu hình với `laravel_app` nhưng được cài đặt thêm **FFmpeg** để đảm nhận nhiệm vụ chuyển đổi video MP4 thành định dạng HLS.
4.  **`laravel_db` (db)**: Hệ quản trị cơ sở dữ liệu MySQL 8.0 lưu trữ thông tin về các bản ghi video (tiêu đề, trạng thái xử lý, đường dẫn lưu trữ, thời gian thực hiện).
5.  **`laravel_minio` (minio)**: S3-compatible Object Storage hoạt động cục bộ. Đóng vai trò là kho lưu trữ đám mây cho các file phân đoạn video HLS đã được chuyển đổi.
6.  **`laravel_minio_init` (minio-init)**: Dịch vụ chạy một lần (one-shot container) sử dụng công cụ `minio/mc` để tự động khởi tạo S3 Bucket (`videos`) và gán quyền đọc công khai (`download` policy), sửa triệt để lỗi 403 Forbidden khi trình duyệt phát video.
7.  **`laravel_init` (init)**: Dịch vụ chạy một lần giúp tự động tạo App Key và thực hiện Migration cơ sở dữ liệu ngay khi MySQL khởi động thành công.

---

## 🔄 Luồng Hoạt Động Chi Tiết (Step-by-Step Data Flow)

Hành trình từ khi một video MP4 được tải lên cho đến khi nó được truyền phát dưới dạng HLS diễn ra qua 6 bước chính dưới đây:

### 1. Khởi Tạo Hệ Thống (System Bootstrap)
*   Khi bạn chạy lệnh `docker-compose up -d`, các container đồng loạt khởi động.
*   **MySQL & MinIO** sẵn sàng nhận kết nối.
*   Container **`laravel_minio_init`** chạy một lệnh script ngắn:
    1. Kết nối với dịch vụ `minio:9000` thông qua mạng nội bộ Docker.
    2. Kiểm tra xem bucket `videos` (được cấu hình trong `.env`) đã tồn tại chưa. Nếu chưa, nó sẽ tạo mới.
    3. Thiết lập chính sách truy cập ẩn danh (Anonymous Policy) cho bucket này thành `download`. Việc này cho phép bất kỳ ai (bao gồm trình duyệt client) đọc trực tiếp các file trong bucket mà không cần chữ ký số S3 (S3 Presigned URL).
*   Container **`laravel_init`** chờ đợi MySQL sẵn sàng, sau đó thực hiện lệnh tạo app key `php artisan key:generate` và chạy migration `php artisan migrate` để thiết lập cấu trúc bảng cơ sở dữ liệu.

### 2. Upload Video Gốc (Video Uploading)
*   Quản trị viên truy cập vào Filament Admin UI tại địa chỉ `http://localhost:8000/admin`.
*   Tại mục quản lý Video, chọn **"Create Video"**, nhập tiêu đề và chọn một file video định dạng MP4 từ máy tính của mình.
*   Khi nhấn **"Save"**, Filament sử dụng thành phần `FileUpload` cấu hình với Disk `local`. File video MP4 gốc được upload lên máy chủ và lưu tạm thời trong thư mục `storage/app/private/temp_videos` của container `laravel_app`.
*   Một bản ghi mới được thêm vào bảng `videos` trong database với trạng thái mặc định ban đầu là `pending` (Chờ xử lý).

### 3. Đẩy Job Vào Hàng Đợi (Job Dispatching)
*   Ngay sau khi bản ghi video được lưu thành công vào cơ sở dữ liệu, sự kiện Hook `afterCreate()` trong class `CreateVideo` của Filament Resource được kích hoạt.
*   Hệ thống thực hiện điều phối công việc bằng lệnh:
    ```php
    ConvertVideoForStreaming::dispatch($this->record);
    ```
*   Một Job mới đại diện cho tiến trình xử lý video này được chèn vào bảng `jobs` trong cơ sở dữ liệu MySQL (do cấu hình `QUEUE_CONNECTION=database`).

### 4. Chuyển Đổi Video Sang HLS Bằng FFmpeg (HLS Conversion & Processing)
*   Container **`laravel_queue`** liên tục lắng nghe cơ sở dữ liệu bảng `jobs`. Khi phát hiện thấy Job `ConvertVideoForStreaming`, nó sẽ lập tức giành quyền xử lý.
*   Hệ thống chuyển đổi trạng thái của bản ghi video trong DB từ `pending` sang `processing` và ghi nhận thời điểm bắt đầu xử lý.
*   Job sử dụng thư viện `ProtoneMedia\LaravelFFMpeg` để gọi bộ công cụ **FFmpeg** được tích hợp sẵn trong container:
    1. Đọc file video gốc định dạng MP4 từ thư mục lưu trữ tạm thời `temp_videos` trên ổ đĩa `local`.
    2. Tiến hành mã hóa lại video (Transcoding) thành codec video phổ biến H.264 (`X264`) với 3 mức độ phân giải và băng thông khác nhau nhằm hỗ trợ phát luồng thích ứng (Adaptive Bitrate Streaming):
        *   **Chất lượng Thấp (Low)**: Scale về độ phân giải **360p** (chiều rộng tự động tương ứng), nén với bitrate **250 kbps**.
        *   **Chất lượng Trung Bình (Mid)**: Scale về độ phân giải **480p**, nén với bitrate **500 kbps**.
        *   **Chất lượng Cao (High)**: Scale về độ phân giải **720p**, nén với bitrate **1000 kbps**.
    3. Chia nhỏ video thành các phân đoạn ngắn (thường là 10 giây mỗi file) định dạng `.ts` (MPEG-2 Transport Stream).
    4. Tạo ra các file danh sách phát playlist tương ứng cho từng chất lượng (`playlist_0_250.m3u8`, `playlist_1_500.m3u8`, `playlist_2_1000.m3u8`).
    5. Tạo ra một file danh sách phát chính (Master Playlist) có tên `playlist.m3u8` đóng vai trò điều phối, chứa liên kết đến các playlist chất lượng cụ thể kèm theo thông số băng thông tương ứng.

### 5. Lưu Trữ Vào MinIO Và Dọn Dẹp (Storage & Cleanup)
*   Trong quá trình FFmpeg xuất bản định dạng HLS, nhờ cấu hình lưu trữ `toDisk('minio')`, các file playlist `.m3u8` và hàng chục file phân đoạn `.ts` được upload trực tiếp từ container `laravel_queue` sang container `laravel_minio` qua kết nối mạng nội bộ Docker.
*   Đường dẫn lưu trữ trên MinIO có cấu trúc: `hls/{video_id}/playlist.m3u8` (và các file `.ts` nằm cùng thư mục).
*   Khi tiến trình FFmpeg hoàn tất thành công:
    *   Job tính toán tổng thời gian xử lý thực tế (`processing_seconds`).
    *   Cập nhật trạng thái bản ghi video trong DB thành `completed`.
    *   Lưu đường dẫn Master Playlist vào trường `hls_path` (`hls/{video_id}/playlist.m3u8`).
    *   **Thực hiện dọn dẹp**: Xóa hoàn toàn file video MP4 gốc ban đầu khỏi thư mục tạm `temp_videos` trên Disk `local` để giải phóng dung lượng ổ cứng cho máy chủ.

### 6. Truyền Phát Video Đến Trình Duyệt (Video HLS Streaming)
*   Khi quản trị viên truy cập danh sách video trong Filament Admin, các video có trạng thái `completed` sẽ hiển thị thêm nút **"Xem Video"** (Play).
*   Khi nhấn nút này, một hộp thoại Modal hiện ra, kích hoạt view Blade `video-player.blade.php`.
*   Laravel lấy địa chỉ URL tuyệt đối của video bằng phương thức:
    ```php
    Storage::disk('minio')->url($record->hls_path)
    ```
    Dựa trên cấu hình `.env`, URL trả về sẽ có dạng: `http://localhost:9000/videos/hls/{video_id}/playlist.m3u8`.
*   Trình phát trên giao diện sử dụng thư viện Javascript **HLS.js** nạp từ CDN:
    1. Trình phát gửi một yêu cầu GET HTTP để tải file Master Playlist `playlist.m3u8` trực tiếp từ cổng `9000` của MinIO trên máy chủ Host.
    2. Trình duyệt phân tích cú pháp file Master Playlist và chọn chất lượng video phù hợp nhất với tốc độ mạng hiện tại của người dùng.
    3. Nó liên tục tải các file phân đoạn nhỏ `.ts` tương ứng và ghép nối chúng lại để phát video mượt mà, không bị gián đoạn. Người dùng có thể tự do chuyển đổi giữa các chất lượng 360p, 480p, hoặc 720p ngay trên thanh điều khiển của trình phát.

---

## 🌐 Điểm Mấu Chốt Về Mạng (Networking & CORS Solution)

Một trong những vấn đề phức tạp nhất khi phát triển hệ thống lưu trữ S3 Local bằng Docker là sự khác biệt về góc nhìn mạng giữa **Backend Server (Laravel)** và **Client (Trình duyệt)**. Dự án này đã xử lý tối ưu vấn đề này qua hai cấu hình quan trọng sau:

### 1. Phân Tách Endpoint MinIO Nội Bộ Và Ngoại Vi
Trong file cấu hình môi trường `.env`, hai biến cấu hình MinIO được phân tách rõ ràng:
*   `AWS_ENDPOINT=http://minio:9000`: Đây là Endpoint nội bộ Docker. Container `laravel_app` và `laravel_queue` sử dụng tên service `minio` để kết nối trực tiếp với container MinIO thông qua mạng Docker Bridge nhanh chóng và ổn định, tránh đi vòng ra ngoài card mạng vật lý của máy Host.
*   `AWS_URL=http://localhost:9000/videos`: Đây là Endpoint ngoại vi. Khi Laravel sinh ra URL công khai để trả về cho trình duyệt Client (`Storage::disk('minio')->url(...)`), nó bắt buộc phải sử dụng domain `localhost` (hoặc IP máy thật của bạn) vì trình duyệt của Client nằm ngoài mạng Docker, không thể hiểu được tên host nội bộ `http://minio`.

### 2. Tự Động Hóa CORS & Quyền Truy Cập (Anonymous Download)
Để trình duyệt Client có thể tải trực tiếp các file phân đoạn `.ts` và playlist `.m3u8` từ cổng `9000` của MinIO mà không bị chặn bởi cơ chế bảo mật trình duyệt:
*   **CORS**: Cấu hình biến môi trường `MINIO_API_CORS_ALLOW_ORIGIN="*"` trong container MinIO cho phép mọi nguồn gốc (Origin) gửi yêu cầu fetch dữ liệu.
*   **Public Access**: Container `laravel_minio_init` tự động chạy lệnh `mc anonymous set download local/videos` giúp biến toàn bộ file trong bucket `videos` thành công khai đối với các yêu cầu đọc (Read-only). Trình duyệt có thể tải mượt mà các file đa phương tiện HLS mà không cần gửi kèm S3 Authorization Header.

---
![Sơ đồ hoạt động](https://github.com/thieuat7/UPLOAD_VIDEO/blob/main/123.png)
---

## 🚀 Hướng Dẫn Khởi Chạy Hệ Thống Nhanh (Quick Start)

Làm theo các bước sau để chạy thử nghiệm toàn bộ luồng hoạt động trên máy tính của bạn:

### Bước 1: Sao chép dự án và cấu hình biến môi trường
1. Clone dự án về máy tính.
2. Di chuyển vào thư mục gốc chứa file `docker-compose.yml`.
3. Kiểm tra file cấu hình `.env` tại thư mục `src/.env`. Hãy đảm bảo các thông số cổng kết nối và key lưu trữ MinIO chính xác như cấu hình mặc định.

### Bước 2: Khởi động toàn bộ dịch vụ bằng Docker Compose
Mở Terminal tại thư mục gốc của dự án và chạy lệnh sau để build và khởi động các container ở chế độ chạy ngầm:
```bash
docker-compose up -d --build
```
*Lệnh này sẽ tự động tải các image cần thiết, build các container PHP-FPM / Queue (tích hợp cài đặt sẵn thư viện FFmpeg), thiết lập database, khởi tạo bucket MinIO công khai và tự động migrate cơ sở dữ liệu.*

### Bước 3: Tạo tài khoản Quản trị viên (Admin)
Để đăng nhập vào trang quản trị Filament Admin, bạn cần tạo một tài khoản User. Chạy lệnh sau trực tiếp trên container `laravel_app`:
```bash
docker-compose exec app php artisan make:filament-user
```
*Nhập Tên, Email và Mật khẩu theo yêu cầu hiển thị trên màn hình.*

### Bước 4: Thử nghiệm tải lên và xem truyền phát video HLS
1. Mở trình duyệt và truy cập vào trang quản trị Filament Admin: [http://localhost:8000/admin](http://localhost:8000/admin).
2. Đăng nhập bằng tài khoản Admin vừa tạo ở Bước 3.
3. Chọn mục **Videos** trên thanh menu bên trái, nhấn **"Create"**.
4. Nhập tiêu đề video và tải lên một file video định dạng MP4 ngắn từ máy tính của bạn. Nhấn **"Save"**.
5. Bạn sẽ thấy bản ghi video xuất hiện trong danh sách với trạng thái **`pending`** hoặc **`processing`**.
6. Hệ thống đang tiến hành chuyển đổi ngầm. Bạn có thể theo dõi tiến trình xử lý trực tiếp thông qua log của container queue bằng lệnh:
   ```bash
   docker-compose logs -f queue
   ```
7. Sau khi quá trình chuyển đổi hoàn tất, trạng thái của video sẽ chuyển sang **`completed`** kèm theo tổng thời gian xử lý (ví dụ: `15.4 sec`).
8. Nút **"Xem Video"** (màu xanh lá) sẽ xuất hiện ở dòng tương ứng. Hãy nhấn vào nút này, một trình phát video cao cấp sẽ hiển thị và phát trực tiếp luồng video HLS đa độ phân giải được lấy trực tiếp từ MinIO!

---
