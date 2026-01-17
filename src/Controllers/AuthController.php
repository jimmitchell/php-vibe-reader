<?php

namespace PhpRss\Controllers;

use PhpRss\Auth;
use PhpRss\View;

class AuthController
{
    public function loginPage(): void
    {
        if (Auth::check()) {
            header('Location: /dashboard');
            exit;
        }
        View::render('login');
    }

    public function login(): void
    {
        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';

        if (empty($username) || empty($password)) {
            View::render('login', ['error' => 'Please enter both username and password']);
            return;
        }

        if (Auth::login($username, $password)) {
            header('Location: /dashboard');
            exit;
        }

        View::render('login', ['error' => 'Invalid username or password']);
    }

    public function registerPage(): void
    {
        if (Auth::check()) {
            header('Location: /dashboard');
            exit;
        }
        View::render('register');
    }

    public function register(): void
    {
        $username = $_POST['username'] ?? '';
        $email = $_POST['email'] ?? '';
        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if (empty($username) || empty($email) || empty($password)) {
            View::render('register', ['error' => 'All fields are required']);
            return;
        }

        if ($password !== $confirmPassword) {
            View::render('register', ['error' => 'Passwords do not match']);
            return;
        }

        if (strlen($password) < 6) {
            View::render('register', ['error' => 'Password must be at least 6 characters']);
            return;
        }

        if (Auth::register($username, $email, $password)) {
            View::render('login', ['success' => 'Registration successful. Please login.']);
            return;
        }

        View::render('register', ['error' => 'Username or email already exists']);
    }

    public function logout(): void
    {
        Auth::logout();
        header('Location: /');
        exit;
    }
}
