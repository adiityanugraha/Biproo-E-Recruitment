<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/** Area recruiter hanya untuk recruiter yang login (kandidat tidak lolos di sini). */
class RecruiterAuth implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        if (! session('recruiter_id')) {
            return redirect()->to('/recruiter/login')->with('error', 'Silakan login sebagai recruiter.');
        }
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
    }
}
